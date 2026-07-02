<?php

namespace App\Trading\Backtest;

use App\Trading\Data\Candle;
use InvalidArgumentException;

/**
 * Cross-sectional momentum backtest over a universe of symbols.
 *
 * Daily, return-based accounting: every rebalance_days the universe is
 * ranked by trailing return (lookback_days ago to skip_days ago); the top_k
 * symbols with momentum above min_momentum are held equally weighted, the
 * rest of the portfolio sits in cash. Fees are charged on turnover
 * (fee_rate per traded notional). Benchmarks: equal-weight buy-and-hold of
 * the universe and the first universe symbol held outright.
 */
final class PortfolioBacktester
{
    public function __construct(private array $config, private float $feeRate)
    {
    }

    /**
     * @param  array<string, Candle[]>  $seriesBySymbol  Daily candles, oldest first.
     * @return array{
     *     days: int, rebalances: int, totalReturnPct: float, benchmarkReturnPct: float,
     *     firstSymbolReturnPct: float, maxDrawdownPct: float, sharpe: float,
     *     totalFees: float, endingBalance: float, cashDays: int,
     *     equityCurve: array<int, float>, lastAllocation: array<string, float>
     * }
     */
    public function run(array $seriesBySymbol, float $startingBalance): array
    {
        $lookback = (int) $this->config['lookback_days'];
        $skip = (int) $this->config['skip_days'];
        $topK = (int) $this->config['top_k'];
        $rebalanceEvery = max(1, (int) $this->config['rebalance_days']);
        $minMomentum = (float) $this->config['min_momentum'];

        if ($lookback <= $skip) {
            throw new InvalidArgumentException('lookback_days must exceed skip_days.');
        }

        // closes[symbol][dayIndex] aligned on the intersection of all days.
        $closesByDay = [];

        foreach ($seriesBySymbol as $symbol => $candles) {
            foreach ($candles as $candle) {
                $closesByDay[intdiv($candle->openTime, 86_400_000)][$symbol] = $candle->close;
            }
        }

        ksort($closesByDay);
        $symbols = array_keys($seriesBySymbol);
        $days = array_values(array_filter(
            array_keys($closesByDay),
            fn (int $day): bool => count($closesByDay[$day]) === count($symbols),
        ));

        if (count($days) <= $lookback + 1) {
            throw new InvalidArgumentException(sprintf(
                'Not enough overlapping history: %d aligned days, need more than %d (lookback).',
                count($days),
                $lookback,
            ));
        }

        $equity = $startingBalance;
        $peak = $startingBalance;
        $maxDrawdown = 0.0;
        $totalFees = 0.0;
        $rebalances = 0;
        $cashDays = 0;
        $weights = [];      // symbol => weight, sums to <= 1 (rest is cash)
        $equityCurve = [];
        $dailyReturns = [];
        $benchmarkStart = null;

        foreach ($days as $t => $day) {
            if ($t < $lookback) {
                continue;
            }

            $benchmarkStart ??= $t;

            // Apply today's market move to yesterday's holdings.
            if ($t > $benchmarkStart) {
                $previousDay = $days[$t - 1];
                $portfolioReturn = 0.0;

                foreach ($weights as $symbol => $weight) {
                    $portfolioReturn += $weight
                        * ($closesByDay[$day][$symbol] / $closesByDay[$previousDay][$symbol] - 1);
                }

                $equity *= 1 + $portfolioReturn;
                $dailyReturns[] = $portfolioReturn;
            }

            // Rebalance on schedule: rank by momentum, hold the qualifying top_k.
            if (($t - $benchmarkStart) % $rebalanceEvery === 0) {
                $momentum = [];

                foreach ($symbols as $symbol) {
                    $past = $closesByDay[$days[$t - $lookback]][$symbol];
                    $recent = $closesByDay[$days[$t - $skip]][$symbol];
                    $momentum[$symbol] = $recent / $past - 1;
                }

                arsort($momentum);

                $target = [];
                foreach (array_slice($momentum, 0, $topK, true) as $symbol => $value) {
                    if ($value > $minMomentum) {
                        $target[$symbol] = 1.0 / $topK;
                    }
                }

                $turnover = 0.0;
                foreach (array_unique([...array_keys($weights), ...array_keys($target)]) as $symbol) {
                    $turnover += abs(($target[$symbol] ?? 0.0) - ($weights[$symbol] ?? 0.0));
                }

                $fee = $turnover * $this->feeRate * $equity;
                $equity -= $fee;
                $totalFees += $fee;
                $weights = $target;
                $rebalances++;
            }

            if ($weights === []) {
                $cashDays++;
            }

            $equityCurve[] = $equity;
            $peak = max($peak, $equity);
            $maxDrawdown = max($maxDrawdown, $peak > 0 ? ($peak - $equity) / $peak : 0.0);
        }

        // Benchmarks over the identical evaluation window.
        $firstDay = $days[$benchmarkStart];
        $lastDay = end($days);
        $benchmarkReturn = 0.0;

        foreach ($symbols as $symbol) {
            $benchmarkReturn += ($closesByDay[$lastDay][$symbol] / $closesByDay[$firstDay][$symbol] - 1) / count($symbols);
        }

        $firstSymbol = $symbols[0];
        $firstSymbolReturn = $closesByDay[$lastDay][$firstSymbol] / $closesByDay[$firstDay][$firstSymbol] - 1;

        $mean = $dailyReturns === [] ? 0.0 : array_sum($dailyReturns) / count($dailyReturns);
        $variance = 0.0;

        foreach ($dailyReturns as $return) {
            $variance += ($return - $mean) ** 2;
        }

        $std = count($dailyReturns) > 1 ? sqrt($variance / (count($dailyReturns) - 1)) : 0.0;

        return [
            'days' => count($equityCurve),
            'rebalances' => $rebalances,
            'totalReturnPct' => $equity / $startingBalance - 1,
            'benchmarkReturnPct' => $benchmarkReturn,
            'firstSymbolReturnPct' => $firstSymbolReturn,
            'maxDrawdownPct' => $maxDrawdown,
            'sharpe' => $std > 0 ? $mean / $std * sqrt(365) : 0.0,
            'totalFees' => $totalFees,
            'endingBalance' => $equity,
            'cashDays' => $cashDays,
            'equityCurve' => $equityCurve,
            'lastAllocation' => $weights,
        ];
    }
}
