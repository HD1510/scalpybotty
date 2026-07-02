<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Trade;
use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Data\Signal;
use App\Trading\Data\SymbolMeta;
use App\Trading\Data\Ticker;
use App\Trading\Enums\OrderSide;
use App\Trading\Enums\SignalAction;
use App\Trading\Enums\TradeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BotCommandsTest extends TestCase
{
    use RefreshDatabase;

    /** Anonymous Exchange fake with settable ticker/candles/balances and recorded orders. */
    private object $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trading.mode' => 'paper',
            'trading.symbols' => ['BTCUSDT'],
        ]);

        $this->exchange = new class implements Exchange {
            /** @var OrderRequest[] */
            public array $placedOrders = [];

            /** @var OrderResult[] Queued results, consumed FIFO by placeOrder(). */
            public array $orderResults = [];

            /** @var Candle[] */
            public array $candleData = [];

            public float $tickerPrice = 100.0;

            /** @var array<string, float> */
            public array $balances = [];

            public function name(): string
            {
                return 'fake-bot';
            }

            public function candles(string $symbol, string $interval, int $limit = 100): array
            {
                return array_slice($this->candleData, -$limit);
            }

            public function ticker(string $symbol): Ticker
            {
                return new Ticker($symbol, $this->tickerPrice, 1_700_000_000_000);
            }

            public function balance(string $asset): float
            {
                return $this->balances[$asset] ?? 0.0;
            }

            public function symbolMeta(string $symbol): SymbolMeta
            {
                return new SymbolMeta($symbol, 'BTC', 'USDT', 0.00001, 0.01, 5.0);
            }

            public function placeOrder(OrderRequest $request): OrderResult
            {
                $this->placedOrders[] = $request;

                return array_shift($this->orderResults) ?? new OrderResult(
                    orderId: 'fake-'.count($this->placedOrders),
                    symbol: $request->symbol,
                    side: $request->side,
                    status: 'filled',
                    executedQuantity: $request->quantity,
                    averagePrice: $this->tickerPrice,
                    fee: 0.0,
                    feeAsset: 'USDT',
                    timestamp: 1_700_000_000_000,
                    raw: ['fake' => true],
                );
            }
        };

        $this->app->instance(Exchange::class, $this->exchange);
        $this->app->instance(Strategy::class, new class implements Strategy {
            public function name(): string
            {
                return 'test_buy_stub';
            }

            public function warmupPeriod(): int
            {
                return 1;
            }

            public function evaluate(array $candles): Signal
            {
                return new Signal(SignalAction::Buy, 0.9, 95.0, 110.0, 'stub buy signal');
            }
        });
    }

    public function testBotRunOnceOpensTradeWithSignalStopAndTakeProfit(): void
    {
        $this->primeBuyTick();

        $this->artisan('bot:run', ['--once' => true])->assertExitCode(0);

        $trade = Trade::query()->sole();

        $this->assertSame(TradeStatus::Open, $trade->status);
        $this->assertSame('BTCUSDT', $trade->symbol);
        $this->assertEqualsWithDelta(95.0, $trade->stop_loss, 1e-9);
        $this->assertEqualsWithDelta(110.0, $trade->take_profit, 1e-9);
        $this->assertEqualsWithDelta(20.0, $trade->quantity, 1e-9);
        $this->assertEqualsWithDelta(100.0, $trade->entry_price, 1e-9);
        $this->assertNull($trade->pnl);

        $entryOrder = Order::query()->where('trade_id', $trade->id)->sole();

        $this->assertSame(OrderSide::Buy, $entryOrder->side);
        $this->assertEqualsWithDelta(20.0, $entryOrder->quantity, 1e-9);
        $this->assertEqualsWithDelta(100.0, $entryOrder->average_price, 1e-9);
        $this->assertCount(1, $this->exchange->placedOrders);
        $this->assertSame(OrderSide::Buy, $this->exchange->placedOrders[0]->side);
    }

    public function testTickerBelowStopClosesTradeAsStopLoss(): void
    {
        $this->primeBuyTick();
        $this->artisan('bot:run', ['--once' => true])->assertExitCode(0);

        // Second tick: price gaps below the 95.0 stop; the exit market order
        // default-fills at the ticker price.
        $this->exchange->tickerPrice = 90.0;

        $this->artisan('bot:run', ['--once' => true])->assertExitCode(0);

        $trade = Trade::query()->sole();

        $this->assertSame(TradeStatus::Closed, $trade->status);
        $this->assertSame('stop_loss', $trade->close_reason);
        $this->assertNotNull($trade->pnl);
        // (90 - 100) * 20 - entry fee 2.0 - exit fee 0.0
        $this->assertEqualsWithDelta(-202.0, $trade->pnl, 1e-6);
        $this->assertNotNull($trade->closed_at);

        $this->assertCount(2, $this->exchange->placedOrders);
        $this->assertSame(OrderSide::Sell, $this->exchange->placedOrders[1]->side);
    }

    public function testBotStatusExitsZeroWithEmptyDatabase(): void
    {
        $this->exchange->balances = ['USDT' => 10_000.0];

        $this->artisan('bot:status')->assertExitCode(0);
    }

    public function testBotStatusExitsZeroWithOpenTrade(): void
    {
        $this->exchange->balances = ['USDT' => 8_000.0];
        $this->exchange->tickerPrice = 101.0;

        Trade::query()->create([
            'symbol' => 'BTCUSDT',
            'status' => TradeStatus::Open,
            'mode' => 'paper',
            'strategy' => 'test_buy_stub',
            'quantity' => 20.0,
            'entry_price' => 100.0,
            'stop_loss' => 95.0,
            'take_profit' => 110.0,
            'entry_fee' => 2.0,
            'opened_at' => now(),
        ]);

        $this->artisan('bot:status')->assertExitCode(0);
    }

    /**
     * Arrange a first tick that opens a position: candles closing at 100,
     * 10k USDT equity and a realistic entry fill (qty 20 @ 100, fee 2).
     * Position size = (0.01 * 10000) / (100 - 95) = 20.
     */
    private function primeBuyTick(): void
    {
        $this->exchange->candleData = $this->flatCandles(5, 100.0);
        $this->exchange->tickerPrice = 100.0;
        $this->exchange->balances = ['USDT' => 10_000.0];
        $this->exchange->orderResults[] = new OrderResult(
            orderId: 'entry-1',
            symbol: 'BTCUSDT',
            side: OrderSide::Buy,
            status: 'filled',
            executedQuantity: 20.0,
            averagePrice: 100.0,
            fee: 2.0,
            feeAsset: 'USDT',
            timestamp: 1_700_000_000_000,
            raw: ['fake' => true],
        );
    }

    /** @return Candle[] */
    private function flatCandles(int $count, float $price): array
    {
        $candles = [];

        for ($i = 0; $i < $count; $i++) {
            $candles[] = new Candle(
                openTime: $i * 60_000,
                open: $price,
                high: $price + 0.5,
                low: $price - 0.5,
                close: $price,
                volume: 10.0,
                closeTime: $i * 60_000 + 59_999,
            );
        }

        return $candles;
    }
}
