<?php

namespace App\Trading\Exchanges;

use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\HistoricalDataProvider;
use App\Trading\Data\Candle;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Data\SymbolMeta;
use App\Trading\Data\Ticker;
use App\Trading\Exceptions\ExchangeException;

/**
 * Wraps the live exchange so that placing real-money orders requires an
 * explicit acknowledgment (config trading.live_confirmed, set via
 * TRADING_LIVE_CONFIRMED or bot:run --live-confirmed). Every entry point
 * that resolves the Exchange contract inherits the guard — read-only calls
 * pass through freely.
 */
final class LiveGuardExchange implements Exchange, HistoricalDataProvider
{
    public function __construct(private Exchange&HistoricalDataProvider $inner)
    {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function candles(string $symbol, string $interval, int $limit = 100): array
    {
        return $this->inner->candles($symbol, $interval, $limit);
    }

    public function candlesBetween(string $symbol, string $interval, int $startTime, int $endTime): array
    {
        return $this->inner->candlesBetween($symbol, $interval, $startTime, $endTime);
    }

    public function ticker(string $symbol): Ticker
    {
        return $this->inner->ticker($symbol);
    }

    public function balance(string $asset): float
    {
        return $this->inner->balance($asset);
    }

    public function symbolMeta(string $symbol): SymbolMeta
    {
        return $this->inner->symbolMeta($symbol);
    }

    public function placeOrder(OrderRequest $request): OrderResult
    {
        if (config('trading.live_confirmed') !== true) {
            throw new ExchangeException(
                'Refusing to place a LIVE order: trading.live_confirmed is not set. '
                .'Run bot:run with --live-confirmed or set TRADING_LIVE_CONFIRMED=true.',
            );
        }

        return $this->inner->placeOrder($request);
    }
}
