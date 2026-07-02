<?php

namespace App\Trading\Risk;

use App\Models\Trade;
use App\Trading\Data\Signal;
use App\Trading\Enums\TradeStatus;
use App\Trading\Enums\TradingMode;

/**
 * Single authority on position sizing and trade-entry gating.
 *
 * Constructed with config('trading.risk'):
 * risk_per_trade, max_open_trades, max_daily_loss_pct, min_confidence.
 */
final class RiskManager
{
    public function __construct(private array $config)
    {
    }

    /**
     * Why no new trade may be opened right now, or null if entries are allowed.
     *
     * Checks, in order:
     * 1. Open-trade cap: no more than max_open_trades concurrently open
     *    positions in the given mode.
     * 2. Daily-loss circuit breaker: once today's (UTC) realized losses reach
     *    max_daily_loss_pct of equity, entries stay blocked until the next
     *    UTC day.
     */
    public function entryBlockReason(TradingMode $mode, float $equity): ?string
    {
        $openTrades = Trade::query()
            ->where('status', TradeStatus::Open)
            ->where('mode', $mode)
            ->count();

        if ($openTrades >= (int) $this->config['max_open_trades']) {
            return "max open trades reached ({$openTrades})";
        }

        $todayPnl = (float) Trade::query()
            ->where('status', TradeStatus::Closed)
            ->where('mode', $mode)
            ->where('closed_at', '>=', now('UTC')->startOfDay())
            ->sum('pnl');

        $lossLimit = (float) $this->config['max_daily_loss_pct'] * $equity;

        if ($equity > 0 && $todayPnl <= -$lossLimit) {
            return sprintf('daily loss limit hit (today %.2f <= -%.2f)', $todayPnl, $lossLimit);
        }

        return null;
    }

    /** Signals below min_confidence are ignored (entry signals only; exits always pass). */
    public function passesConfidence(Signal $signal): bool
    {
        return $signal->confidence >= (float) $this->config['min_confidence'];
    }

    /**
     * Fixed-fractional position sizing.
     *
     * Risks risk_per_trade of equity on the distance to the stop-loss:
     * qty = (risk_per_trade * equity) / (entryPrice - stopLoss), capped so the
     * notional never exceeds 99% of the available quote balance (fee headroom).
     *
     * Returns 0.0 when the inputs cannot yield a valid long position. The
     * result is unquantized; callers apply SymbolMeta::quantizeQuantity().
     */
    public function positionSize(float $equity, float $availableQuote, float $entryPrice, float $stopLoss): float
    {
        $perUnitRisk = $entryPrice - $stopLoss;

        if ($perUnitRisk <= 0 || $entryPrice <= 0 || $equity <= 0 || $availableQuote <= 0) {
            return 0.0;
        }

        $riskAmount = (float) $this->config['risk_per_trade'] * $equity;
        $qty = $riskAmount / $perUnitRisk;
        $maxAffordableQty = ($availableQuote * 0.99) / $entryPrice;

        return min($qty, $maxAffordableQty);
    }
}
