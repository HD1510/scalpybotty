<?php

namespace App\Trading\Exchanges;

use App\Models\PaperBalance;
use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\HistoricalDataProvider;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Data\SymbolMeta;
use App\Trading\Data\Ticker;
use App\Trading\Enums\OrderSide;
use App\Trading\Exceptions\ExchangeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paper trading exchange: market data is delegated to a real exchange while
 * fills and balances are simulated locally with configurable fee and slippage.
 */
final class PaperExchange implements Exchange, HistoricalDataProvider
{
    public function __construct(
        private Exchange $marketData,
        private array $paperConfig,
        private string $quoteAsset,
    ) {
    }

    public function name(): string
    {
        return 'paper';
    }

    public function candles(string $symbol, string $interval, int $limit = 100): array
    {
        return $this->marketData->candles($symbol, $interval, $limit);
    }

    public function ticker(string $symbol): Ticker
    {
        return $this->marketData->ticker($symbol);
    }

    public function symbolMeta(string $symbol): SymbolMeta
    {
        return $this->marketData->symbolMeta($symbol);
    }

    public function candlesBetween(string $symbol, string $interval, int $startTime, int $endTime): array
    {
        if (! $this->marketData instanceof HistoricalDataProvider) {
            throw new ExchangeException(
                "Underlying exchange [{$this->marketData->name()}] does not provide historical data."
            );
        }

        return $this->marketData->candlesBetween($symbol, $interval, $startTime, $endTime);
    }

    public function balance(string $asset): float
    {
        return $this->findOrSeed($asset);
    }

    public function placeOrder(OrderRequest $request): OrderResult
    {
        if ($request->quantity <= 0) {
            throw new ExchangeException("Order quantity must be positive, got [{$request->quantity}].");
        }

        $meta = $this->symbolMeta($request->symbol);

        if ($meta->quoteAsset !== $this->quoteAsset) {
            throw new ExchangeException(
                "Symbol [{$request->symbol}] is quoted in {$meta->quoteAsset}; paper balances are denominated in {$this->quoteAsset}."
            );
        }

        $ticker = $this->ticker($request->symbol);
        $slippage = (float) $this->paperConfig['slippage_bps'] / 10_000;

        // Slippage is always applied against the trader.
        $fillPrice = $request->side === OrderSide::Buy
            ? $ticker->price * (1 + $slippage)
            : $ticker->price * (1 - $slippage);

        // Buys are entries (optionally modelled as maker limit fills), sells
        // are stop/market exits (taker). BNB discount shaves 25% off both.
        $discount = ($this->paperConfig['fee_bnb_discount'] ?? false) ? 0.75 : 1.0;
        $feeRate = $request->side === OrderSide::Buy && ($this->paperConfig['maker_entries'] ?? false)
            ? (float) ($this->paperConfig['maker_fee_rate'] ?? 0.0)
            : (float) $this->paperConfig['fee_rate'];
        $fee = $feeRate * $discount * $request->quantity * $fillPrice;

        DB::transaction(function () use ($request, $meta, $fillPrice, $fee): void {
            if ($request->side === OrderSide::Buy) {
                $cost = $request->quantity * $fillPrice + $fee;
                $available = $this->lockedBalance($this->quoteAsset);

                if ($available < $cost) {
                    throw new ExchangeException(sprintf(
                        'insufficient paper balance: need %.8f %s, have %.8f',
                        $cost,
                        $this->quoteAsset,
                        $available,
                    ));
                }

                $this->debit($this->quoteAsset, $cost);
                $this->credit($meta->baseAsset, $request->quantity);
            } else {
                $available = $this->lockedBalance($meta->baseAsset);

                if ($available < $request->quantity) {
                    throw new ExchangeException(sprintf(
                        'insufficient paper balance: need %.8f %s, have %.8f',
                        $request->quantity,
                        $meta->baseAsset,
                        $available,
                    ));
                }

                $this->debit($meta->baseAsset, $request->quantity);
                $this->credit($this->quoteAsset, $request->quantity * $fillPrice - $fee);
            }
        });

        return new OrderResult(
            orderId: 'paper-'.Str::ulid(),
            symbol: $request->symbol,
            side: $request->side,
            status: 'filled',
            executedQuantity: $request->quantity,
            averagePrice: $fillPrice,
            fee: $fee,
            feeAsset: $this->quoteAsset,
            timestamp: now()->getTimestampMs(),
            raw: ['simulated' => true, 'ticker_price' => $ticker->price],
        );
    }

    /** Free balance read under a row lock (see findOrSeed for seeding rules). */
    private function lockedBalance(string $asset): float
    {
        return $this->findOrSeed($asset, lock: true);
    }

    /**
     * Return the free balance for an asset, optionally under a row lock.
     *
     * The quote asset row is seeded with the configured starting balance on
     * first access — any balance read creates it, not just order placement;
     * tests rely on this. Caveat: seeding is keyed to the *current* quote
     * asset, so changing TRADING_QUOTE_ASSET mid-experiment mints a fresh
     * starting balance for the new asset while the old row keeps its funds.
     */
    private function findOrSeed(string $asset, bool $lock = false): float
    {
        $query = PaperBalance::query();

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->firstWhere('asset', $asset);

        if ($row === null && $asset === $this->quoteAsset) {
            $row = PaperBalance::query()->create([
                'asset' => $asset,
                'amount' => (float) $this->paperConfig['starting_balance'],
            ]);
        }

        return $row?->amount ?? 0.0;
    }

    private function credit(string $asset, float $amount): void
    {
        $row = PaperBalance::query()->lockForUpdate()->firstOrCreate(
            ['asset' => $asset],
            ['amount' => $amount],
        );

        if (! $row->wasRecentlyCreated) {
            $row->increment('amount', $amount);
        }
    }

    private function debit(string $asset, float $amount): void
    {
        PaperBalance::query()->where('asset', $asset)->decrement('amount', $amount);
    }
}
