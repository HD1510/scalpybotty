<?php

namespace App\Console\Commands;

use App\Trading\Backtest\Backtester;
use App\Trading\Backtest\CsvCandleStore;
use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\HistoricalDataProvider;
use App\Trading\Exceptions\ExchangeException;
use App\Trading\Strategies\StrategyFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use RuntimeException;

final class BotOptimize extends Command
{
    protected $signature = 'bot:optimize
        {--strategy= : Strategy to tune (defaults to trading.strategy)}
        {--csv= : Candle CSV to replay (recommended; see bot:export-data)}
        {--symbol= : Symbol to fetch when no CSV is given (defaults to the first of trading.symbols)}
        {--days=14 : How many days of history to fetch when no CSV is given}
        {--interval= : Candle interval (defaults to trading.interval)}
        {--param=* : Grid override, e.g. --param=fast_ema=5,9,12 --param=slow_ema=21,34}
        {--top=10 : How many results to show}';

    protected $description = 'Grid-search strategy parameters over historical candles and rank the results';

    /**
     * Default parameter grids per strategy, used when no --param overrides
     * are given. Values sweep around the config/trading.php defaults.
     */
    private const DEFAULT_GRIDS = [
        'ema_rsi_scalp' => [
            'fast_ema' => [5, 9, 12],
            'slow_ema' => [21, 26, 34],
            'atr_stop_mult' => [1.0, 1.5, 2.0],
            'atr_tp_mult' => [2.0, 2.5, 3.5],
        ],
        'bollinger_reversion' => [
            'bb_period' => [14, 20, 26],
            'bb_std_dev' => [1.5, 2.0, 2.5],
            'rsi_oversold' => [25.0, 30.0, 35.0],
            'atr_stop_mult' => [1.0, 1.5, 2.0],
        ],
    ];

    public function handle(Exchange $exchange): int
    {
        $name = (string) ($this->option('strategy') ?: config('trading.strategy'));
        $baseParams = config("trading.strategies.{$name}");

        if (! is_array($baseParams) || $baseParams === []) {
            $this->error("Unknown trading strategy [{$name}]. Add it to config/trading.php.");

            return self::FAILURE;
        }

        $symbol = (string) ($this->option('symbol') ?: Arr::first((array) config('trading.symbols'), null, ''));
        $interval = (string) ($this->option('interval') ?: config('trading.interval'));
        $days = max(1, (int) $this->option('days'));

        if ($symbol === '') {
            $this->error('No symbol given and trading.symbols is empty.');

            return self::FAILURE;
        }

        $csvPath = (string) $this->option('csv');

        if ($csvPath !== '') {
            $this->info(sprintf('Optimizing [%s] on %s against candles from %s...', $name, $symbol, $csvPath));

            try {
                $candles = (new CsvCandleStore)->read($csvPath);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        } else {
            if (! $exchange instanceof HistoricalDataProvider) {
                $this->error(sprintf(
                    'Exchange [%s] cannot provide historical data; optimizing needs a HistoricalDataProvider implementation.',
                    $exchange->name(),
                ));

                return self::FAILURE;
            }

            $endTime = now('UTC')->getTimestampMs();
            $startTime = $endTime - $days * 86_400_000;

            $this->info(sprintf('Optimizing [%s] on %s %s over the last %d day(s)...', $name, $symbol, $interval, $days));

            try {
                $candles = $exchange->candlesBetween($symbol, $interval, $startTime, $endTime);
            } catch (ExchangeException $e) {
                $this->error("Failed to fetch candles: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($candles === []) {
            $this->warn('Got 0 candles — check your network connection, symbol, interval or CSV file.');

            return self::FAILURE;
        }

        try {
            $grid = $this->buildGrid($name, $baseParams, (array) $this->option('param'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $combos = $this->cartesianProduct($grid);
        $skipped = 0;

        // Reject nonsensical EMA crossover configurations.
        $combos = array_values(array_filter($combos, function (array $combo) use (&$skipped): bool {
            if (isset($combo['fast_ema'], $combo['slow_ema']) && $combo['fast_ema'] >= $combo['slow_ema']) {
                $skipped++;

                return false;
            }

            return true;
        }));

        if ($combos === []) {
            $this->error('No parameter combinations left to test (all were skipped or the grid is empty).');

            return self::FAILURE;
        }

        if (count($combos) > 500) {
            $this->warn(sprintf('Testing %d combinations — this may take a while.', count($combos)));
        }

        $this->line(sprintf(
            'Replaying %d candles for %d parameter combination(s) [%s]...',
            count($candles),
            count($combos),
            implode(', ', array_keys($grid)),
        ));

        $factory = new StrategyFactory;
        $riskConfig = (array) config('trading.risk');
        $paperConfig = (array) config('trading.paper');
        $startingBalance = (float) $paperConfig['starting_balance'];

        $rows = [];
        $bar = $this->output->createProgressBar(count($combos));
        $bar->start();

        foreach ($combos as $combo) {
            $params = array_merge($baseParams, $combo);
            $strategy = $factory->make($name, $params);
            $result = (new Backtester($strategy, $riskConfig, $paperConfig))->run($candles, $startingBalance);

            $rows[] = [
                'params' => $combo,
                'totalTrades' => $result->totalTrades,
                'winRate' => $result->winRate,
                'profitFactor' => $result->profitFactor,
                'netPnl' => $result->netPnl,
                'netPnlPct' => $result->netPnlPct,
                'maxDrawdownPct' => $result->maxDrawdownPct,
                'totalFees' => $result->totalFees,
            ];

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        usort($rows, fn (array $a, array $b): int => $b['netPnl'] <=> $a['netPnl']);

        $top = max(1, (int) $this->option('top'));

        $this->table(
            ['Params', 'Trades', 'Win %', 'PF', 'Net PnL %', 'MaxDD %', 'Fees'],
            array_map(
                fn (array $row): array => [
                    implode(' ', array_map(
                        static fn (string $key, int|float $value): string => "{$key}={$value}",
                        array_keys($row['params']),
                        array_values($row['params']),
                    )),
                    (string) $row['totalTrades'],
                    $row['totalTrades'] > 0 ? sprintf('%.1f', $row['winRate'] * 100) : '—',
                    sprintf('%.2f', $row['profitFactor']),
                    sprintf('%+.2f', $row['netPnlPct'] * 100),
                    sprintf('%.2f', $row['maxDrawdownPct'] * 100),
                    sprintf('%.2f', $row['totalFees']),
                ],
                array_slice($rows, 0, $top),
            ),
        );

        $best = $rows[0];

        $this->newLine();
        $this->line("Best combination for config/trading.php ('strategies' → '{$name}'):");

        foreach ($best['params'] as $key => $value) {
            $this->line(sprintf(
                "    '%s' => %s,",
                $key,
                is_int($value) ? (string) $value : sprintf('%.1f', $value),
            ));
        }

        $this->newLine();
        $this->line(sprintf('Tested %d combination(s), skipped %d invalid one(s).', count($combos), $skipped));
        $this->warn('These parameters are fitted to this one dataset — validate on out-of-sample data before trusting them.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $baseParams
     * @param  string[]  $overrides  raw --param values, "key=v1,v2,..."
     * @return array<string, array<int, int|float>> parameter name => candidate values
     */
    private function buildGrid(string $name, array $baseParams, array $overrides): array
    {
        $grid = self::DEFAULT_GRIDS[$name] ?? [];

        if ($grid === [] && $overrides === []) {
            throw new RuntimeException(
                "No default grid is defined for strategy [{$name}]; specify one with --param=key=v1,v2,...",
            );
        }

        foreach ($overrides as $override) {
            $parts = explode('=', (string) $override, 2);

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new RuntimeException("Invalid --param [{$override}]; expected key=v1,v2,...");
            }

            [$key, $rawValues] = $parts;

            if (! array_key_exists($key, $baseParams)) {
                throw new RuntimeException(sprintf(
                    'Unknown parameter [%s] for strategy [%s]; known parameters: %s.',
                    $key,
                    $name,
                    implode(', ', array_keys($baseParams)),
                ));
            }

            $castToInt = is_int($baseParams[$key]);
            $values = [];

            foreach (explode(',', $rawValues) as $value) {
                $value = trim($value);

                if ($value === '' || ! is_numeric($value)) {
                    throw new RuntimeException("Invalid --param [{$override}]; value [{$value}] is not numeric.");
                }

                $values[] = $castToInt ? (int) $value : (float) $value;
            }

            $grid[$key] = array_values(array_unique($values, SORT_REGULAR));
        }

        return $grid;
    }

    /**
     * @param  array<string, array<int, int|float>>  $grid
     * @return array<int, array<string, int|float>> every combination of one value per key
     */
    private function cartesianProduct(array $grid): array
    {
        $combos = [[]];

        foreach ($grid as $key => $values) {
            $next = [];

            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$key => $value];
                }
            }

            $combos = $next;
        }

        return $combos;
    }
}
