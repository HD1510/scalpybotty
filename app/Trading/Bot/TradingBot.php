<?php

namespace App\Trading\Bot;

use App\Models\BotEvent;
use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\Trade;
use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Data\Ticker;
use App\Trading\Enums\OrderSide;
use App\Trading\Enums\SignalAction;
use App\Trading\Enums\TradeStatus;
use App\Trading\Enums\TradingMode;
use App\Trading\Exceptions\ExchangeException;
use App\Trading\Risk\RiskManager;
use App\Trading\Support\Num;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The live/paper trading engine. Each tick() evaluates every configured
 * symbol once: open positions are managed (stop-loss, take-profit, exit
 * signal) and new positions are opened through the risk manager; finally an
 * equity snapshot is recorded.
 */
final class TradingBot
{
    /**
     * Per-tick ticker memo, keyed by symbol; reset at the start of tick().
     *
     * @var array<string, Ticker>
     */
    private array $tickerCache = [];

    /** Per-tick quote-asset balance memo; reset at the start of tick(). */
    private ?float $balanceCache = null;

    public function __construct(
        private Exchange $exchange,
        private Strategy $strategy,
        private RiskManager $risk,
    ) {
    }

    /**
     * Run one evaluation cycle over config('trading.symbols').
     *
     * @return string[] Human-readable log lines describing what happened.
     */
    public function tick(): array
    {
        $this->tickerCache = [];
        $this->balanceCache = null;

        $mode = TradingMode::from(config('trading.mode'));
        $lines = [];

        foreach (config('trading.symbols') as $symbol) {
            try {
                $trade = $this->openTradeFor($symbol, $mode);

                $lines[] = $trade !== null
                    ? $this->manageOpenTrade($trade)
                    : $this->tryEnter($symbol, $mode);
            } catch (Throwable $e) {
                $lines[] = sprintf('%s: %s — %s', $symbol, $e::class, $e->getMessage());
            }
        }

        try {
            $lines[] = $this->snapshotEquity($mode);
        } catch (ExchangeException $e) {
            $lines[] = "equity snapshot failed: {$e->getMessage()}";
        }

        $this->persistEvents($mode, $lines);

        return $lines;
    }

    /**
     * Mirror the tick's log lines into bot_events so the dashboard can show
     * live activity. Events older than 7 days are pruned; persistence must
     * never break the trading loop.
     */
    private function persistEvents(TradingMode $mode, array $lines): void
    {
        try {
            $now = now();

            BotEvent::insert(array_map(fn (string $line): array => [
                'mode' => $mode->value,
                'level' => $this->eventLevel($line),
                'message' => $line,
                'created_at' => $now,
                'updated_at' => $now,
            ], $lines));

            BotEvent::where('created_at', '<', $now->copy()->subDays(7))->delete();
        } catch (Throwable $e) {
            Log::warning("could not persist bot events: {$e->getMessage()}");
        }
    }

    private function eventLevel(string $line): string
    {
        $lower = strtolower($line);

        return match (true) {
            str_contains($lower, 'critical') => 'error',
            str_contains($lower, 'failed'), str_contains($lower, 'exception'), str_contains($lower, 'error') => 'error',
            str_contains($lower, 'rejected'), str_contains($lower, 'blocked'), str_contains($lower, 'skipped') => 'warning',
            default => 'info',
        };
    }

    /**
     * Manage an existing position: hard stop-loss / take-profit checks first
     * (against the live ticker), then the strategy's exit signal.
     */
    private function manageOpenTrade(Trade $trade): string
    {
        $price = $this->cachedTicker($trade->symbol)->price;

        if ($price <= $trade->stop_loss) {
            return $this->closeTrade($trade, 'stop_loss');
        }

        if ($price >= $trade->take_profit) {
            return $this->closeTrade($trade, 'take_profit');
        }

        $signal = $this->strategy->evaluate($this->candles($trade->symbol));

        if ($signal->action === SignalAction::Sell) {
            // Exit signals bypass the confidence gate; it only guards entries.
            return $this->closeTrade($trade, 'signal');
        }

        return sprintf(
            '%s: holding trade #%d — price %s, SL %s, TP %s, unrealized %s',
            $trade->symbol,
            $trade->id,
            $this->num($price),
            $this->num($trade->stop_loss),
            $this->num($trade->take_profit),
            $this->num($trade->unrealizedPnl($price)),
        );
    }

    private function tryEnter(string $symbol, TradingMode $mode): string
    {
        $candles = $this->candles($symbol);

        if ($candles === []) {
            return "{$symbol}: no candles returned — skipping";
        }

        $signal = $this->strategy->evaluate($candles);

        if ($signal->action !== SignalAction::Buy) {
            return "{$symbol}: {$signal->action->value}"
                .($signal->reason !== '' ? " — {$signal->reason}" : '');
        }

        if ($signal->stopLoss === null || $signal->takeProfit === null) {
            return "{$symbol}: buy signal without stop-loss/take-profit — ignored";
        }

        if (! $this->risk->passesConfidence($signal)) {
            return sprintf(
                '%s: buy signal confidence %.2f below minimum — skipped',
                $symbol,
                $signal->confidence,
            );
        }

        $equity = $this->computeEquity($mode);
        $blockReason = $this->risk->entryBlockReason($mode, $equity);

        if ($blockReason !== null) {
            return "{$symbol}: entry blocked — {$blockReason}";
        }

        $quoteBalance = $this->cachedBalance();
        $lastClose = $candles[array_key_last($candles)]->close;
        $meta = $this->exchange->symbolMeta($symbol);

        $qty = $meta->quantizeQuantity(
            $this->risk->positionSize($equity, $quoteBalance, $lastClose, $signal->stopLoss),
        );

        if ($qty <= 0 || ! $meta->meetsMinNotional($qty, $lastClose)) {
            return sprintf(
                '%s: entry skipped — quantity %s at %s is below step size or min notional',
                $symbol,
                $this->num($qty),
                $this->num($lastClose),
            );
        }

        $result = $this->exchange->placeOrder(new OrderRequest(
            $symbol,
            OrderSide::Buy,
            $qty,
            'sb-e-'.$symbol.'-'.now()->getTimestampMs(),
        ));

        if (! $result->isFilled()) {
            return "{$symbol}: entry order {$result->status} — no trade opened";
        }

        $trade = DB::transaction(function () use ($symbol, $mode, $signal, $result): Trade {
            $trade = Trade::query()->create([
                'symbol' => $symbol,
                'status' => TradeStatus::Open,
                'mode' => $mode,
                'strategy' => $this->strategy->name(),
                'quantity' => $result->executedQuantity,
                'entry_price' => $result->averagePrice,
                'stop_loss' => $signal->stopLoss,
                'take_profit' => $signal->takeProfit,
                'entry_fee' => $result->fee,
                'entry_reason' => $signal->reason,
                'opened_at' => now(),
            ]);

            $this->persistOrder($result, $mode, $trade->id);

            return $trade;
        }, 3);

        return sprintf(
            '%s: opened trade #%d — qty %s @ %s, SL %s, TP %s (%s)',
            $symbol,
            $trade->id,
            $this->num($trade->quantity),
            $this->num($trade->entry_price),
            $this->num($trade->stop_loss),
            $this->num($trade->take_profit),
            $signal->reason,
        );
    }

    /** Market-sell the full position and settle the trade row. */
    private function closeTrade(Trade $trade, string $reason): string
    {
        $meta = $this->exchange->symbolMeta($trade->symbol);
        $qty = $meta->quantizeQuantity($trade->quantity);

        if ($qty <= 0) {
            return "{$trade->symbol}: cannot close trade #{$trade->id} — quantity quantizes to zero";
        }

        $result = $this->exchange->placeOrder(new OrderRequest(
            $trade->symbol,
            OrderSide::Sell,
            $qty,
            'sb-x-'.$trade->id.'-'.now()->getTimestampMs(),
        ));

        if (! $result->isFilled()) {
            return "exit order rejected for {$trade->symbol}, keeping trade open (will retry next tick)";
        }

        $executedQty = $result->executedQuantity;
        $notional = $trade->entry_price * $executedQty;
        $pnl = ($result->averagePrice - $trade->entry_price) * $executedQty
            - $trade->entry_fee
            - $result->fee;
        $pnlPct = $notional > 0 ? $pnl / $notional : null;

        try {
            DB::transaction(function () use ($trade, $result, $reason, $pnl, $pnlPct): void {
                $this->persistOrder($result, $trade->mode, $trade->id);

                $trade->update([
                    'exit_price' => $result->averagePrice,
                    'exit_fee' => $result->fee,
                    'pnl' => $pnl,
                    'pnl_pct' => $pnlPct,
                    'status' => TradeStatus::Closed,
                    'closed_at' => now(),
                    'close_reason' => $reason,
                ]);
            }, 3);
        } catch (Throwable $e) {
            return sprintf(
                '%s: CRITICAL — trade #%d position SOLD on exchange (order %s) but the DB update '
                .'failed (%s: %s); trade row still marked open, manual reconciliation required',
                $trade->symbol,
                $trade->id,
                $result->orderId,
                $e::class,
                $e->getMessage(),
            );
        }

        $line = sprintf(
            '%s: closed trade #%d (%s) — exit %s, PnL %s (%s)',
            $trade->symbol,
            $trade->id,
            $reason,
            $this->num($result->averagePrice),
            $this->num($pnl),
            $pnlPct === null ? 'n/a' : sprintf('%.2f%%', $pnlPct * 100),
        );

        if ($executedQty < $qty) {
            $line .= sprintf(
                '; partial fill — %s %s unsold dust remains',
                $this->num($qty - $executedQty),
                $meta->baseAsset,
            );
        }

        return $line;
    }

    /** Free quote balance plus the market value of all open positions in this mode. */
    private function computeEquity(TradingMode $mode): float
    {
        $breakdown = $this->equityBreakdown($mode);

        return $breakdown['quoteBalance'] + $breakdown['positionValue'];
    }

    private function snapshotEquity(TradingMode $mode): string
    {
        $breakdown = $this->equityBreakdown($mode);
        $equity = $breakdown['quoteBalance'] + $breakdown['positionValue'];

        EquitySnapshot::query()->create([
            'mode' => $mode,
            'equity' => $equity,
            'quote_balance' => $breakdown['quoteBalance'],
            'unrealized_pnl' => $breakdown['unrealizedPnl'],
            'open_trades' => $breakdown['openCount'],
        ]);

        return sprintf(
            'equity %s %s — balance %s, unrealized %s, %d open trade(s)',
            $this->num($equity),
            config('trading.quote_asset'),
            $this->num($breakdown['quoteBalance']),
            $this->num($breakdown['unrealizedPnl']),
            $breakdown['openCount'],
        );
    }

    /**
     * Shared equity math for computeEquity() and snapshotEquity().
     *
     * @return array{quoteBalance: float, positionValue: float, unrealizedPnl: float, openCount: int}
     */
    private function equityBreakdown(TradingMode $mode): array
    {
        $openTrades = $this->openTrades($mode);

        $positionValue = 0.0;
        $unrealizedPnl = 0.0;

        foreach ($openTrades as $trade) {
            $price = $this->cachedTicker($trade->symbol)->price;
            $positionValue += $trade->quantity * $price;
            $unrealizedPnl += $trade->unrealizedPnl($price);
        }

        return [
            'quoteBalance' => $this->cachedBalance(),
            'positionValue' => $positionValue,
            'unrealizedPnl' => $unrealizedPnl,
            'openCount' => $openTrades->count(),
        ];
    }

    /** Ticker memoized for the current tick. */
    private function cachedTicker(string $symbol): Ticker
    {
        return $this->tickerCache[$symbol] ??= $this->exchange->ticker($symbol);
    }

    /** Quote-asset balance memoized for the current tick. */
    private function cachedBalance(): float
    {
        return $this->balanceCache ??= $this->exchange->balance((string) config('trading.quote_asset'));
    }

    private function persistOrder(OrderResult $result, TradingMode $mode, ?int $tradeId): void
    {
        Order::query()->create([
            'trade_id' => $tradeId,
            'exchange_order_id' => $result->orderId,
            'symbol' => $result->symbol,
            'side' => $result->side,
            'mode' => $mode,
            'status' => $result->status,
            'quantity' => $result->executedQuantity,
            'average_price' => $result->averagePrice,
            'fee' => $result->fee,
            'fee_asset' => $result->feeAsset,
            'raw' => $result->raw,
            'executed_at' => Carbon::createFromTimestampMs($result->timestamp),
        ]);
    }

    private function openTradeFor(string $symbol, TradingMode $mode): ?Trade
    {
        return Trade::query()
            ->where('symbol', $symbol)
            ->where('status', TradeStatus::Open)
            ->where('mode', $mode)
            ->first();
    }

    /** @return Collection<int, Trade> */
    private function openTrades(TradingMode $mode): Collection
    {
        return Trade::query()
            ->where('status', TradeStatus::Open)
            ->where('mode', $mode)
            ->get();
    }

    /** @return Candle[] */
    private function candles(string $symbol): array
    {
        return $this->exchange->candles(
            $symbol,
            (string) config('trading.interval'),
            max((int) config('trading.candle_limit'), $this->strategy->warmupPeriod()),
        );
    }

    /** Compact number for log lines: up to 8 decimals, trailing zeros trimmed. */
    private function num(float $value): string
    {
        return Num::trim($value);
    }
}
