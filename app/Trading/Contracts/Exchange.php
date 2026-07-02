<?php

namespace App\Trading\Contracts;

use App\Trading\Data\Candle;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Data\SymbolMeta;
use App\Trading\Data\Ticker;

/**
 * A spot exchange the bot can trade on.
 *
 * Implementations: BinanceExchange (real REST API, mainnet or testnet) and
 * PaperExchange (real market data, simulated fills and balances).
 *
 * All methods throw App\Trading\Exceptions\ExchangeException on API or
 * network failure so callers only need to handle one exception type.
 */
interface Exchange
{
    /** Short identifier, e.g. 'binance' or 'paper'. */
    public function name(): string;

    /**
     * Recent closed candles, oldest first. The currently forming candle is
     * NOT included — strategies must only ever see closed candles.
     *
     * @param  string  $interval  Exchange interval string, e.g. '1m', '5m', '1h'.
     * @return Candle[]
     */
    public function candles(string $symbol, string $interval, int $limit = 100): array;

    /** Current price. */
    public function ticker(string $symbol): Ticker;

    /** Free (available) balance of a single asset, e.g. 'USDT'. */
    public function balance(string $asset): float;

    /** Trading rules (step size, tick size, min notional). Implementations should cache this. */
    public function symbolMeta(string $symbol): SymbolMeta;

    /** Execute a market order. Never returns a partially-resting order. */
    public function placeOrder(OrderRequest $request): OrderResult;
}
