<?php

namespace App\Trading\Backtest;

use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Enums\SignalAction;
use App\Trading\Risk\RiskManager;

/**
 * Event-driven replay of a strategy over closed historical candles.
 *
 * No look-ahead: the signal for step i is computed from a fixed trailing
 * window of candles ending at index i (mirroring the bounded history the
 * live bot feeds the strategy), and every fill happens on candle i+1 (the
 * next bar), never on the signal bar. The entry bar itself is checked for
 * stop/take-profit hits immediately after the fill, and exits are
 * gap-aware: a candle that opens through the stop fills at the (worse)
 * open, one that opens through the take-profit fills at the (better) open.
 *
 * Fills use the same fee/slippage model as PaperExchange. Sizing and
 * confidence gating are not replicas: they ARE RiskManager
 * (positionSize() and passesConfidence(), both database-free), so the
 * backtest cannot drift from live sizing. The daily-loss circuit breaker
 * from RiskManager::entryBlockReason() is simulated in-memory per UTC day.
 */
final class Backtester
{
    private const MS_PER_DAY = 86_400_000;

    private RiskManager $riskManager;

    public function __construct(
        private Strategy $strategy,
        private array $riskConfig,
        private array $paperConfig,
        private int $evaluationWindow = 150,
    ) {
        $this->riskManager = new RiskManager($this->riskConfig);
    }

    /**
     * @param  Candle[]  $candles  Closed candles, oldest first.
     */
    public function run(array $candles, float $startingBalance): BacktestResult
    {
        $candles = array_values($candles);
        $count = count($candles);

        $feeRate = (float) ($this->paperConfig['fee_rate'] ?? 0.0);
        $slippage = (float) ($this->paperConfig['slippage_bps'] ?? 0.0) / 1e4;
        $maxDailyLossPct = (float) ($this->riskConfig['max_daily_loss_pct'] ?? 0.0);

        $balance = $startingBalance;
        $totalFees = 0.0;
        $trades = [];
        $equityCurve = [];

        /** @var array<int, float> $dailyPnl Realized pnl keyed by UTC day number. */
        $dailyPnl = [];

        /** @var array{entry_time: int, entry: float, qty: float, stop: float, tp: float, fees: float}|null $position */
        $position = null;

        $close = function (float $rawExit, int $exitTime, string $reason, int $exitCandleCloseTime) use (
            &$balance,
            &$totalFees,
            &$trades,
            &$position,
            &$dailyPnl,
            $slippage,
            $feeRate
        ): void {
            $exit = $rawExit * (1 - $slippage);
            $proceeds = $position['qty'] * $exit;
            $fee = $feeRate * $proceeds;
            $balance += $proceeds - $fee;
            $totalFees += $fee;

            $pnl = ($exit - $position['entry']) * $position['qty'] - $position['fees'] - $fee;

            // Realized pnl is bucketed by the UTC day of the exit candle's
            // close, feeding the daily-loss circuit breaker below.
            $day = intdiv($exitCandleCloseTime, self::MS_PER_DAY);
            $dailyPnl[$day] = ($dailyPnl[$day] ?? 0.0) + $pnl;

            $trades[] = [
                'entry_time' => $position['entry_time'],
                'exit_time' => $exitTime,
                'entry' => $position['entry'],
                'exit' => $exit,
                'qty' => $position['qty'],
                'pnl' => $pnl,
                'reason' => $reason,
            ];

            $position = null;
        };

        // Resting stop/take-profit orders fill intra-candle. Checked
        // pessimistically: when both levels lie inside the same candle, the
        // stop-loss wins. Gap-aware: a candle that opens beyond the stop
        // fills at the (worse) open; one that opens beyond the take-profit
        // fills at the (better) open.
        $checkExits = function (Candle $candle) use (&$position, $close): void {
            if ($candle->low <= $position['stop']) {
                $close(min($position['stop'], $candle->open), $candle->closeTime, 'stop_loss', $candle->closeTime);
            } elseif ($candle->high >= $position['tp']) {
                $close(max($position['tp'], $candle->open), $candle->closeTime, 'take_profit', $candle->closeTime);
            }
        };

        // Fixed trailing evaluation window, matching the bounded candle
        // history the live bot fetches (never below the strategy's warmup).
        $window = max($this->evaluationWindow, $this->strategy->warmupPeriod());
        $visible = fn (int $i): array => array_slice($candles, max(0, $i + 1 - $window), min($i + 1, $window));

        for ($i = $this->strategy->warmupPeriod() - 1; $i + 1 < $count; $i++) {
            $next = $candles[$i + 1];

            if ($position !== null) {
                $checkExits($next);

                if ($position !== null) {
                    $signal = $this->strategy->evaluate($visible($i));

                    if ($signal->action === SignalAction::Sell) {
                        $close($next->open, $next->openTime, 'signal', $next->closeTime);
                    }
                }
            } else {
                $signal = $this->strategy->evaluate($visible($i));

                if (
                    $signal->action === SignalAction::Buy
                    && $signal->stopLoss !== null
                    && $signal->takeProfit !== null
                    && $this->riskManager->passesConfidence($signal)
                ) {
                    // Daily-loss circuit breaker, in parity with the live
                    // gate in RiskManager::entryBlockReason(): once the
                    // current UTC day's realized losses reach
                    // max_daily_loss_pct of equity, entries stay blocked
                    // until the next UTC day.
                    $dayPnl = $dailyPnl[intdiv($next->openTime, self::MS_PER_DAY)] ?? 0.0;
                    $breakerTripped = $maxDailyLossPct > 0
                        && $balance > 0
                        && $dayPnl <= -($maxDailyLossPct * $balance);

                    if (! $breakerTripped) {
                        $entry = $next->open * (1 + $slippage);

                        // While flat, equity equals the quote balance.
                        $qty = $this->riskManager->positionSize($balance, $balance, $entry, $signal->stopLoss);

                        if ($qty > 0) {
                            $fee = $feeRate * $qty * $entry;
                            $balance -= $qty * $entry + $fee;
                            $totalFees += $fee;

                            $position = [
                                'entry_time' => $next->openTime,
                                'entry' => $entry,
                                'qty' => $qty,
                                'stop' => $signal->stopLoss,
                                'tp' => $signal->takeProfit,
                                'fees' => $fee,
                            ];

                            // The entry bar itself can already reach the stop
                            // or take-profit; check it before advancing.
                            $checkExits($next);
                        }
                    }
                }
            }

            $equityCurve[] = $balance + ($position !== null ? $position['qty'] * $next->close : 0.0);
        }

        if ($position !== null) {
            $last = $candles[$count - 1];
            $close($last->close, $last->closeTime, 'end_of_data', $last->closeTime);
            $equityCurve[] = $balance;
        }

        return $this->summarize($startingBalance, $balance, $totalFees, $trades, $equityCurve);
    }

    /**
     * @param  array<int, array{entry_time: int, exit_time: int, entry: float, exit: float, qty: float, pnl: float, reason: string}>  $trades
     * @param  float[]  $equityCurve
     */
    private function summarize(
        float $startingBalance,
        float $endingBalance,
        float $totalFees,
        array $trades,
        array $equityCurve,
    ): BacktestResult {
        $wins = 0;
        $losses = 0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;

        foreach ($trades as $trade) {
            if ($trade['pnl'] > 0) {
                $wins++;
                $grossProfit += $trade['pnl'];
            } elseif ($trade['pnl'] < 0) {
                $losses++;
                $grossLoss += -$trade['pnl'];
            }
        }

        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($equityCurve as $equity) {
            $peak = max($peak, $equity);

            if ($peak > 0) {
                $maxDrawdown = max($maxDrawdown, ($peak - $equity) / $peak);
            }
        }

        $totalTrades = count($trades);
        $netPnl = $endingBalance - $startingBalance;

        return new BacktestResult(
            totalTrades: $totalTrades,
            wins: $wins,
            losses: $losses,
            winRate: $totalTrades > 0 ? $wins / $totalTrades : 0.0,
            grossProfit: $grossProfit,
            grossLoss: $grossLoss,
            profitFactor: $grossLoss > 0 ? $grossProfit / $grossLoss : 0.0,
            netPnl: $netPnl,
            netPnlPct: $startingBalance > 0 ? $netPnl / $startingBalance : 0.0,
            totalFees: $totalFees,
            maxDrawdownPct: $maxDrawdown,
            startingBalance: $startingBalance,
            endingBalance: $endingBalance,
            trades: $trades,
            equityCurve: $equityCurve,
        );
    }
}
