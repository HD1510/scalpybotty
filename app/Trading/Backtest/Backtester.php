<?php

namespace App\Trading\Backtest;

use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Enums\SignalAction;

/**
 * Event-driven replay of a strategy over closed historical candles.
 *
 * No look-ahead: the signal for step i is computed from candles[0..i] only,
 * and every fill happens on candle i+1 (the next bar), never on the signal
 * bar. Fills use the same fee/slippage model as PaperExchange, and sizing
 * replicates RiskManager's fixed-fractional formula without touching the
 * database.
 */
final class Backtester
{
    public function __construct(
        private Strategy $strategy,
        private array $riskConfig,
        private array $paperConfig,
    ) {
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
        $riskPerTrade = (float) ($this->riskConfig['risk_per_trade'] ?? 0.0);
        $minConfidence = (float) ($this->riskConfig['min_confidence'] ?? 0.0);

        $balance = $startingBalance;
        $totalFees = 0.0;
        $trades = [];
        $equityCurve = [];

        /** @var array{entry_time: int, entry: float, qty: float, stop: float, tp: float, fees: float}|null $position */
        $position = null;

        $close = function (float $rawExit, int $exitTime, string $reason) use (
            &$balance,
            &$totalFees,
            &$trades,
            &$position,
            $slippage,
            $feeRate
        ): void {
            $exit = $rawExit * (1 - $slippage);
            $proceeds = $position['qty'] * $exit;
            $fee = $feeRate * $proceeds;
            $balance += $proceeds - $fee;
            $totalFees += $fee;

            $trades[] = [
                'entry_time' => $position['entry_time'],
                'exit_time' => $exitTime,
                'entry' => $position['entry'],
                'exit' => $exit,
                'qty' => $position['qty'],
                'pnl' => ($exit - $position['entry']) * $position['qty'] - $position['fees'] - $fee,
                'reason' => $reason,
            ];

            $position = null;
        };

        for ($i = $this->strategy->warmupPeriod() - 1; $i + 1 < $count; $i++) {
            $next = $candles[$i + 1];

            if ($position !== null) {
                // Resting stop/take-profit orders fill intra-candle. Checked
                // pessimistically: when both levels lie inside the same
                // candle, the stop-loss wins.
                if ($next->low <= $position['stop']) {
                    $close($position['stop'], $next->closeTime, 'stop_loss');
                } elseif ($next->high >= $position['tp']) {
                    $close($position['tp'], $next->closeTime, 'take_profit');
                } else {
                    $signal = $this->strategy->evaluate(array_slice($candles, 0, $i + 1));

                    if ($signal->action === SignalAction::Sell) {
                        $close($next->open, $next->openTime, 'signal');
                    }
                }
            } else {
                $signal = $this->strategy->evaluate(array_slice($candles, 0, $i + 1));

                if (
                    $signal->action === SignalAction::Buy
                    && $signal->stopLoss !== null
                    && $signal->takeProfit !== null
                    && $signal->confidence >= $minConfidence
                ) {
                    $entry = $next->open * (1 + $slippage);
                    $stop = $signal->stopLoss;

                    if ($entry > 0 && $stop < $entry && $balance > 0) {
                        // While flat, equity equals the quote balance.
                        $qty = min(
                            ($riskPerTrade * $balance) / ($entry - $stop),
                            ($balance * 0.99) / $entry,
                        );

                        if ($qty > 0) {
                            $fee = $feeRate * $qty * $entry;
                            $balance -= $qty * $entry + $fee;
                            $totalFees += $fee;

                            $position = [
                                'entry_time' => $next->openTime,
                                'entry' => $entry,
                                'qty' => $qty,
                                'stop' => $stop,
                                'tp' => $signal->takeProfit,
                                'fees' => $fee,
                            ];
                        }
                    }
                }
            }

            $equityCurve[] = $balance + ($position !== null ? $position['qty'] * $next->close : 0.0);
        }

        if ($position !== null) {
            $last = $candles[$count - 1];
            $close($last->close, $last->closeTime, 'end_of_data');
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
