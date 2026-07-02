<?php

namespace Tests\Support;

use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\HistoricalDataProvider;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Data\SymbolMeta;
use App\Trading\Data\Ticker;

/**
 * Reusable test double for market-data providing exchanges.
 *
 * Ticker price, candles and symbol metadata are settable; every
 * OrderRequest passed to placeOrder() is recorded and answered with a
 * configurable (or default) OrderResult. No network access.
 */
final class FakeMarketDataExchange implements Exchange, HistoricalDataProvider
{
    /** @var OrderRequest[] */
    public array $placedOrders = [];

    /** @var array<int, \App\Trading\Data\Candle> */
    private array $candles = [];

    private ?SymbolMeta $symbolMeta = null;

    private ?OrderResult $nextOrderResult = null;

    public function __construct(
        private float $tickerPrice = 0.0,
        private string $name = 'fake',
    ) {
    }

    public function setTickerPrice(float $price): void
    {
        $this->tickerPrice = $price;
    }

    /** @param  array<int, \App\Trading\Data\Candle>  $candles */
    public function setCandles(array $candles): void
    {
        $this->candles = $candles;
    }

    public function setSymbolMeta(SymbolMeta $meta): void
    {
        $this->symbolMeta = $meta;
    }

    public function setNextOrderResult(OrderResult $result): void
    {
        $this->nextOrderResult = $result;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function candles(string $symbol, string $interval, int $limit = 100): array
    {
        return array_slice($this->candles, -$limit);
    }

    public function candlesBetween(string $symbol, string $interval, int $startTime, int $endTime): array
    {
        return array_values(array_filter(
            $this->candles,
            fn ($candle): bool => $candle->openTime >= $startTime && $candle->openTime <= $endTime,
        ));
    }

    public function ticker(string $symbol): Ticker
    {
        return new Ticker($symbol, $this->tickerPrice, now()->getTimestampMs());
    }

    public function balance(string $asset): float
    {
        return 0.0;
    }

    public function symbolMeta(string $symbol): SymbolMeta
    {
        return $this->symbolMeta ?? new SymbolMeta(
            symbol: $symbol,
            baseAsset: substr($symbol, 0, -4),
            quoteAsset: substr($symbol, -4),
            stepSize: 0.0,
            tickSize: 0.0,
            minNotional: 0.0,
        );
    }

    public function placeOrder(OrderRequest $request): OrderResult
    {
        $this->placedOrders[] = $request;

        if ($this->nextOrderResult !== null) {
            $result = $this->nextOrderResult;
            $this->nextOrderResult = null;

            return $result;
        }

        return new OrderResult(
            orderId: 'fake-'.count($this->placedOrders),
            symbol: $request->symbol,
            side: $request->side,
            status: 'filled',
            executedQuantity: $request->quantity,
            averagePrice: $this->tickerPrice,
            fee: 0.0,
            feeAsset: $this->symbolMeta($request->symbol)->quoteAsset,
            timestamp: now()->getTimestampMs(),
            raw: ['fake' => true],
        );
    }
}
