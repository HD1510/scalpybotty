<?php

namespace App\Console\Commands;

use App\Models\PaperBalance;
use App\Models\Trade;
use App\Trading\Contracts\Exchange;
use App\Trading\Enums\TradeStatus;
use App\Trading\Enums\TradingMode;
use App\Trading\Exceptions\ExchangeException;
use App\Trading\Support\Num;
use Illuminate\Console\Command;

final class BotStatus extends Command
{
    protected $signature = 'bot:status';

    protected $description = 'Show open trades, realized PnL, equity and balances for the current trading mode';

    public function handle(Exchange $exchange): int
    {
        $mode = TradingMode::from(config('trading.mode'));
        $quoteAsset = (string) config('trading.quote_asset');

        $this->info(sprintf(
            'Mode: %s | Exchange: %s | Strategy: %s',
            $mode->value,
            $exchange->name(),
            config('trading.strategy'),
        ));
        $this->newLine();

        $openTrades = Trade::query()
            ->where('status', TradeStatus::Open)
            ->where('mode', $mode)
            ->orderBy('opened_at')
            ->get();

        $positionValue = 0.0;

        if ($openTrades->isEmpty()) {
            $this->line('No open trades — the bot is flat.');
        } else {
            $rows = [];

            foreach ($openTrades as $trade) {
                try {
                    $price = $exchange->ticker($trade->symbol)->price;
                } catch (ExchangeException) {
                    $price = null;
                }

                // Fall back to the entry price so equity stays an estimate
                // instead of silently dropping the position.
                $positionValue += $trade->quantity * ($price ?? $trade->entry_price);

                $rows[] = [
                    $trade->symbol,
                    $this->num($trade->quantity),
                    $this->num($trade->entry_price),
                    $price !== null ? $this->num($price) : 'n/a',
                    $this->num($trade->stop_loss),
                    $this->num($trade->take_profit),
                    $price !== null ? $this->num($trade->unrealizedPnl($price)) : 'n/a',
                ];
            }

            $this->table(
                ['Symbol', 'Qty', 'Entry', 'Current', 'Stop Loss', 'Take Profit', 'Unrealized PnL'],
                $rows,
            );
        }

        $closedToday = Trade::query()
            ->where('status', TradeStatus::Closed)
            ->where('mode', $mode)
            ->where('closed_at', '>=', now('UTC')->startOfDay())
            ->get();

        $this->newLine();
        $this->line(sprintf(
            'Today (UTC): %d closed trade(s), realized PnL %s %s',
            $closedToday->count(),
            $this->num((float) $closedToday->sum('pnl')),
            $quoteAsset,
        ));

        try {
            $quoteBalance = $exchange->balance($quoteAsset);

            $this->line(sprintf(
                'Equity: %s %s (free balance %s + open positions %s)',
                $this->num($quoteBalance + $positionValue),
                $quoteAsset,
                $this->num($quoteBalance),
                $this->num($positionValue),
            ));
        } catch (ExchangeException $e) {
            $this->warn("Equity unavailable — {$e->getMessage()}");
        }

        if ($mode === TradingMode::Paper) {
            $this->newLine();

            // The equity read above already seeded the quote asset balance
            // (PaperExchange seeds it on first access), so at least one row
            // always exists here.
            $this->table(
                ['Asset', 'Amount'],
                PaperBalance::query()
                    ->orderBy('asset')
                    ->get()
                    ->map(fn (PaperBalance $balance): array => [
                        $balance->asset,
                        $this->num($balance->amount),
                    ])->all(),
            );
        }

        return self::SUCCESS;
    }

    /** Compact number formatting: up to 8 decimals, trailing zeros trimmed. */
    private function num(float $value): string
    {
        return Num::trim($value);
    }
}
