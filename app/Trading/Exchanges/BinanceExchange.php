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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Binance Spot REST API client (mainnet or testnet, selected via config).
 */
final class BinanceExchange implements Exchange, HistoricalDataProvider
{
    private const KLINES_PAGE_LIMIT = 1000;

    private const PAGE_SLEEP_MICROSECONDS = 200_000;

    /** @var array<string, SymbolMeta> */
    private array $symbolMetaCache = [];

    public function __construct(private array $config)
    {
    }

    public function name(): string
    {
        return 'binance';
    }

    public function candles(string $symbol, string $interval, int $limit = 100): array
    {
        // Binance includes the currently forming candle as the last row, so
        // request one extra (clamped to the API maximum of 1000) and drop
        // anything that has not closed yet. Slice from the end so the newest
        // closed candles are kept.
        $rows = $this->publicRequest('GET', '/api/v3/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => min($limit + 1, self::KLINES_PAGE_LIMIT),
        ]);

        return array_slice($this->closedCandles($rows), -$limit);
    }

    public function candlesBetween(string $symbol, string $interval, int $startTime, int $endTime): array
    {
        $candles = [];
        $cursor = $startTime;

        while ($cursor <= $endTime) {
            $rows = $this->publicRequest('GET', '/api/v3/klines', [
                'symbol' => $symbol,
                'interval' => $interval,
                'startTime' => $cursor,
                'endTime' => $endTime,
                'limit' => self::KLINES_PAGE_LIMIT,
            ]);

            if ($rows === []) {
                break;
            }

            $candles = array_merge($candles, $this->closedCandles($rows));

            $lastRow = end($rows);
            $cursor = ((int) $lastRow[6]) + 1;

            if (count($rows) < self::KLINES_PAGE_LIMIT) {
                break;
            }

            usleep(self::PAGE_SLEEP_MICROSECONDS);
        }

        return $candles;
    }

    public function ticker(string $symbol): Ticker
    {
        $data = $this->publicRequest('GET', '/api/v3/ticker/price', [
            'symbol' => $symbol,
        ]);

        $price = $data['price'] ?? null;

        if (! is_numeric($price) || (float) $price <= 0) {
            throw new ExchangeException(
                "Binance returned no usable price for [{$symbol}] on [/api/v3/ticker/price].",
            );
        }

        return new Ticker(
            symbol: $symbol,
            price: (float) $price,
            timestamp: $this->nowMilliseconds(),
        );
    }

    public function symbolMeta(string $symbol): SymbolMeta
    {
        if (isset($this->symbolMetaCache[$symbol])) {
            return $this->symbolMetaCache[$symbol];
        }

        return $this->symbolMetaCache[$symbol] = Cache::remember(
            'binance.meta.'.$symbol,
            3600,
            fn (): SymbolMeta => $this->fetchSymbolMeta($symbol),
        );
    }

    public function balance(string $asset): float
    {
        $data = $this->signedRequest('GET', '/api/v3/account');

        foreach ($data['balances'] ?? [] as $entry) {
            if (($entry['asset'] ?? null) === $asset) {
                return (float) $entry['free'];
            }
        }

        return 0.0;
    }

    public function placeOrder(OrderRequest $request): OrderResult
    {
        $params = [
            'symbol' => $request->symbol,
            'side' => strtoupper($request->side->value),
            'type' => 'MARKET',
            'quantity' => $this->formatQuantity($request->quantity),
        ];

        if ($request->clientOrderId !== null) {
            $params['newClientOrderId'] = $request->clientOrderId;
        }

        $data = $this->signedRequest('POST', '/api/v3/order', $params);
        $meta = $this->symbolMeta($request->symbol);

        $executedQuantity = (float) ($data['executedQty'] ?? 0.0);
        $fills = $data['fills'] ?? [];

        $filledQuantity = 0.0;
        $notional = 0.0;
        $commissions = [];

        foreach ($fills as $fill) {
            $quantity = (float) ($fill['qty'] ?? 0.0);
            $filledQuantity += $quantity;
            $notional += $quantity * (float) ($fill['price'] ?? 0.0);

            $asset = (string) ($fill['commissionAsset'] ?? '');
            $commissions[$asset] = ($commissions[$asset] ?? 0.0) + (float) ($fill['commission'] ?? 0.0);
        }

        $averagePrice = $filledQuantity > 0 ? $notional / $filledQuantity : 0.0;

        // Binance denominates commission per fill (market BUYs pay in the base
        // asset, SELLs in the quote asset, BNB-discount accounts in BNB).
        // Quote commission is fee as-is. Base commission reduces the base
        // amount actually received, so subtract it from executedQuantity and
        // convert it to quote at the volume-weighted average price. Any other
        // asset has no reliable conversion here: exclude it from fee and
        // surface it in raw['unconverted_commissions'] instead.
        $fee = $commissions[$meta->quoteAsset] ?? 0.0;
        $baseCommission = $commissions[$meta->baseAsset] ?? 0.0;

        if ($baseCommission > 0) {
            $executedQuantity -= $baseCommission;
            $fee += $baseCommission * $averagePrice;
        }

        $unconverted = array_filter(
            $commissions,
            fn (float $amount, string $asset): bool => $amount > 0
                && $asset !== $meta->baseAsset
                && $asset !== $meta->quoteAsset,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($unconverted !== []) {
            $data['unconverted_commissions'] = $unconverted;
        }

        $exchangeStatus = (string) ($data['status'] ?? '');
        $filled = in_array($exchangeStatus, ['FILLED', 'PARTIALLY_FILLED'], true) && $executedQuantity > 0;

        return new OrderResult(
            orderId: (string) ($data['orderId'] ?? ''),
            symbol: (string) ($data['symbol'] ?? $request->symbol),
            side: $request->side,
            status: $filled ? 'filled' : 'rejected',
            executedQuantity: $executedQuantity,
            averagePrice: $averagePrice,
            fee: $fee,
            feeAsset: $meta->quoteAsset,
            timestamp: (int) ($data['transactTime'] ?? $this->nowMilliseconds()),
            raw: $data,
        );
    }

    private function fetchSymbolMeta(string $symbol): SymbolMeta
    {
        $data = $this->publicRequest('GET', '/api/v3/exchangeInfo', [
            'symbol' => $symbol,
        ]);

        $info = $data['symbols'][0] ?? null;

        if (! is_array($info)) {
            throw new ExchangeException("Binance returned no exchange info for symbol [{$symbol}].");
        }

        $filters = [];

        foreach ($info['filters'] ?? [] as $filter) {
            if (isset($filter['filterType'])) {
                $filters[$filter['filterType']] = $filter;
            }
        }

        return new SymbolMeta(
            symbol: (string) ($info['symbol'] ?? $symbol),
            baseAsset: (string) ($info['baseAsset'] ?? ''),
            quoteAsset: (string) ($info['quoteAsset'] ?? ''),
            stepSize: (float) ($filters['LOT_SIZE']['stepSize'] ?? 0.0),
            tickSize: (float) ($filters['PRICE_FILTER']['tickSize'] ?? 0.0),
            // MIN_NOTIONAL is the pre-2023 shape of the NOTIONAL filter.
            minNotional: (float) ($filters['NOTIONAL']['minNotional']
                ?? $filters['MIN_NOTIONAL']['minNotional']
                ?? 5.0),
        );
    }

    private function publicRequest(string $method, string $path, array $params = []): array
    {
        return $this->request($method, $path, $params, signed: false);
    }

    private function signedRequest(string $method, string $path, array $params = []): array
    {
        return $this->request($method, $path, $params, signed: true);
    }

    private function request(string $method, string $path, array $params, bool $signed): array
    {
        $url = $this->baseUrl().$path;

        try {
            $client = Http::timeout((int) $this->config['timeout']);

            if ($signed) {
                $params['timestamp'] = $this->nowMilliseconds();
                $params['recvWindow'] = (int) $this->config['recv_window'];
                $params['signature'] = hash_hmac(
                    'sha256',
                    http_build_query($params),
                    (string) $this->config['secret'],
                );
                $client = $client->withHeaders([
                    'X-MBX-APIKEY' => (string) $this->config['key'],
                ]);
            }

            $response = $method === 'POST'
                ? $client->asForm()->post($url, $params)
                : $client->get($url, $params);
        } catch (ConnectionException $exception) {
            throw new ExchangeException(
                "Binance request to [{$path}] failed: {$exception->getMessage()}",
                0,
                $exception,
            );
        } catch (RequestException $exception) {
            // Guzzle errors that carry a response (e.g. a proxy answering the
            // CONNECT with 403) are marshalled past the failed() check below.
            throw new ExchangeException($this->errorMessage($path, $exception->response), 0, $exception);
        }

        if ($response->failed()) {
            throw new ExchangeException($this->errorMessage($path, $response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ExchangeException(
                "Binance response on [{$path}] did not decode to a JSON array (HTTP {$response->status()}).",
            );
        }

        return $body;
    }

    private function errorMessage(string $path, Response $response): string
    {
        $body = $response->json();
        $status = $response->status();

        if (is_array($body) && (isset($body['code']) || isset($body['msg']))) {
            $code = $body['code'] ?? 'unknown';
            $message = $body['msg'] ?? 'no message';

            return "Binance error on [{$path}] (HTTP {$status}): [{$code}] {$message}";
        }

        return "Binance error on [{$path}]: HTTP {$status}";
    }

    private function baseUrl(): string
    {
        $url = ($this->config['testnet'] ?? false)
            ? $this->config['testnet_base_url']
            : $this->config['base_url'];

        return rtrim((string) $url, '/');
    }

    /**
     * Map raw kline rows to Candles, dropping any candle that has not closed yet.
     *
     * @param  array<int, array<int, mixed>>  $rows  Raw Binance kline rows.
     * @return array<int, Candle>
     */
    private function closedCandles(array $rows): array
    {
        $now = $this->nowMilliseconds();
        $candles = [];

        foreach ($rows as $row) {
            if ((int) $row[6] >= $now) {
                continue;
            }

            $candles[] = $this->mapKline($row);
        }

        return $candles;
    }

    /**
     * @param  array<int, mixed>  $row  Raw Binance kline row.
     */
    private function mapKline(array $row): Candle
    {
        return new Candle(
            openTime: (int) $row[0],
            open: (float) $row[1],
            high: (float) $row[2],
            low: (float) $row[3],
            close: (float) $row[4],
            volume: (float) $row[5],
            closeTime: (int) $row[6],
        );
    }

    /** Binance rejects quantities with superfluous trailing zeros/dot. */
    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.');
    }

    // Deliberately real wall clock: Binance signing needs it; Carbon::setTestNow must not freeze request timestamps.
    private function nowMilliseconds(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
