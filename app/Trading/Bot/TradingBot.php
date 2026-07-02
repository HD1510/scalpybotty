<?php

namespace App\Trading\Bot;

use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\Trade;
use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Enums\OrderSide;
use App\Trading\Enums\SignalAction;
use App\Trading\Enums\TradeStatus;
use App\Trading\Enums\TradingMode;
use App\Trading\Exceptions\ExchangeException;
use App\Trading\Risk\RiskManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The live/paper trading engine. Each tick() evaluates every configured
 * symbol once: open positions are managed (stop-loss, take-profit, exit
 * signal) and new positions are opened through the risk manager; finally an
 * equity snapshot is recorded.
 */
final class TradingBot
{
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
        $mode = TradingMode::from(config('trading.mode'));
        $lines = [];

        foreach (config('trading.symbols') as $symbol) {
            try {
                $trade = $this->openTradeFor($symbol, $mode);

                $lines[] = $trade !== null
                    ? $this->manageOpenTrade($trade)
                    : $this->tryEnter($symbol, $mode);
            } catch (ExchangeException $e) {
                $lines[] = "{$symbol}: exchange error — {$e->getMessage()}";
            }
        }

        try {
            $lines[] = $this->snapshotEquity($mode);
        } catch (ExchangeException $e) {
            $lines[] = "equity snapshot failed: {$e->getMessage()}";
        }

        return $lines;
    }

    /**
     * Manage an existing position: hard stop-loss / take-profit checks first
     * (against the live ticker), then the strategy's exit signal.
     */
    private function manageOpenTrade(Trade $trade): string
    {
        $price = $this->exchange->ticker($trade->symbol)->price;

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

        $quoteBalance = $this->exchange->balance((string) config('trading.quote_asset'));
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

        $result = $this->exchange->placeOrder(new OrderRequest($symbol, OrderSide::Buy, $qty));

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
        });

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

        $result = $this->exchange->placeOrder(new OrderRequest($trade->symbol, OrderSide::Sell, $qty));

        DB::transaction(function () use ($trade, $result, $reason): void {
            $this->persistOrder($result, $trade->mode, $trade->id);

            $notional = $trade->entry_price * $trade->quantity;
            $pnl = ($result->averagePrice - $trade->entry_price) * $trade->quantity
                - $trade->entry_fee
                - $result->fee;

            $trade->update([
                'exit_price' => $result->averagePrice,
                'exit_fee' => $result->fee,
                'pnl' => $pnl,
                'pnl_pct' => $notional > 0 ? $pnl / $notional : 0.0,
                'status' => TradeStatus::Closed,
                'closed_at' => now(),
                'close_reason' => $reason,
            ]);
        });

        return sprintf(
            '%s: closed trade #%d (%s) — exit %s, PnL %s (%.2f%%)',
            $trade->symbol,
            $trade->id,
            $reason,
            $this->num($trade->exit_price),
            $this->num($trade->pnl),
            $trade->pnl_pct * 100,
        );
    }

    /** Free quote balance plus the market value of all open positions in this mode. */
    private function computeEquity(TradingMode $mode): float
    {
        $equity = $this->exchange->balance((string) config('trading.quote_asset'));

        foreach ($this->openTrades($mode) as $trade) {
            $equity += $trade->quantity * $this->exchange->ticker($trade->symbol)->price;
        }

        return $equity;
    }

    private function snapshotEquity(TradingMode $mode): string
    {
        $quoteBalance = $this->exchange->balance((string) config('trading.quote_asset'));
        $openTrades = $this->openTrades($mode);

        $positionValue = 0.0;
        $unrealizedPnl = 0.0;

        foreach ($openTrades as $trade) {
            $price = $this->exchange->ticker($trade->symbol)->price;
            $positionValue += $trade->quantity * $price;
            $unrealizedPnl += $trade->unrealizedPnl($price);
        }

        $equity = $quoteBalance + $positionValue;

        EquitySnapshot::query()->create([
            'mode' => $mode,
            'equity' => $equity,
            'quote_balance' => $quoteBalance,
            'unrealized_pnl' => $unrealizedPnl,
            'open_trades' => $openTrades->count(),
        ]);

        return sprintf(
            'equity %s %s — balance %s, unrealized %s, %d open trade(s)',
            $this->num($equity),
            config('trading.quote_asset'),
            $this->num($quoteBalance),
            $this->num($unrealizedPnl),
            $openTrades->count(),
        );
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
            (int) config('trading.candle_limit'),
        );
    }

    /** Compact number for log lines: up to 8 decimals, trailing zeros trimmed. */
    private function num(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
