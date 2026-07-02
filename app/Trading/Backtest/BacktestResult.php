<?php

namespace App\Trading\Backtest;

/**
 * Aggregated outcome of a single backtest run.
 */
final readonly class BacktestResult
{
    public function __construct(
        public int $totalTrades,
        public int $wins,
        public int $losses,
        /** Fraction of winning trades, 0..1; 0.0 when no trades were taken. */
        public float $winRate,
        public float $grossProfit,
        /** Sum of losing trades' PnL as a positive number. */
        public float $grossLoss,
        /** grossProfit / grossLoss; 0.0 (not INF) when grossLoss is zero. */
        public float $profitFactor,
        public float $netPnl,
        /** Net PnL as a fraction of the starting balance. */
        public float $netPnlPct,
        public float $totalFees,
        /** Largest peak-to-trough decline of the equity curve, 0..1. */
        public float $maxDrawdownPct,
        public float $startingBalance,
        public float $endingBalance,
        /**
         * Closed trades, oldest first.
         *
         * @var array<int, array{entry_time: int, exit_time: int, entry: float, exit: float, qty: float, pnl: float, reason: string}>
         */
        public array $trades,
        /** @var float[] Mark-to-market equity after each replayed candle. */
        public array $equityCurve,
    ) {
    }
}
