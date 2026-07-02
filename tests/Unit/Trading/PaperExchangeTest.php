<?php

namespace Tests\Unit\Trading;

use App\Models\PaperBalance;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\SymbolMeta;
use App\Trading\Enums\OrderSide;
use App\Trading\Exceptions\ExchangeException;
use App\Trading\Exchanges\PaperExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMarketDataExchange;
use Tests\TestCase;

class PaperExchangeTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketDataExchange $marketData;

    private PaperExchange $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marketData = new FakeMarketDataExchange(tickerPrice: 100.0);
        $this->marketData->setSymbolMeta(new SymbolMeta(
            symbol: 'BTCUSDT',
            baseAsset: 'BTC',
            quoteAsset: 'USDT',
            stepSize: 0.00001,
            tickSize: 0.01,
            minNotional: 5.0,
        ));

        $this->exchange = new PaperExchange(
            $this->marketData,
            [
                'starting_balance' => 10000.0,
                'fee_rate' => 0.001,
                'slippage_bps' => 100.0, // 1% for easy math
            ],
            'USDT',
        );
    }

    public function test_first_quote_balance_access_seeds_starting_balance(): void
    {
        $this->assertEqualsWithDelta(10000.0, $this->exchange->balance('USDT'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->exchange->balance('BTC'), 1e-9);
    }

    public function test_buy_applies_slippage_and_fee_and_moves_balances(): void
    {
        $result = $this->exchange->placeOrder(
            new OrderRequest('BTCUSDT', OrderSide::Buy, 10.0),
        );

        // Fill at 100 * 1.01 = 101, fee = 0.001 * 10 * 101 = 1.01,
        // total cost = 1010 + 1.01 = 1011.01.
        $this->assertSame('filled', $result->status);
        $this->assertSame(OrderSide::Buy, $result->side);
        $this->assertSame('USDT', $result->feeAsset);
        $this->assertEqualsWithDelta(101.0, $result->averagePrice, 1e-9);
        $this->assertEqualsWithDelta(10.0, $result->executedQuantity, 1e-9);
        $this->assertEqualsWithDelta(1.01, $result->fee, 1e-9);

        $this->assertEqualsWithDelta(10000.0 - 1011.01, $this->exchange->balance('USDT'), 1e-9);
        $this->assertEqualsWithDelta(10.0, $this->exchange->balance('BTC'), 1e-9);
    }

    public function test_sell_applies_slippage_and_fee_and_moves_balances(): void
    {
        $this->exchange->placeOrder(new OrderRequest('BTCUSDT', OrderSide::Buy, 10.0));

        $result = $this->exchange->placeOrder(
            new OrderRequest('BTCUSDT', OrderSide::Sell, 10.0),
        );

        // Fill at 100 * 0.99 = 99, fee = 0.001 * 10 * 99 = 0.99,
        // proceeds = 990 - 0.99 = 989.01.
        $this->assertSame('filled', $result->status);
        $this->assertEqualsWithDelta(99.0, $result->averagePrice, 1e-9);
        $this->assertEqualsWithDelta(0.99, $result->fee, 1e-9);

        $this->assertEqualsWithDelta(10000.0 - 1011.01 + 989.01, $this->exchange->balance('USDT'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->exchange->balance('BTC'), 1e-9);
    }

    public function test_buy_exceeding_quote_balance_throws_and_leaves_balances_unchanged(): void
    {
        // Cost would be 100 * 101 * 1.001 = 10111.01 > 10000.
        try {
            $this->exchange->placeOrder(new OrderRequest('BTCUSDT', OrderSide::Buy, 100.0));
            $this->fail('Expected ExchangeException for insufficient quote balance.');
        } catch (ExchangeException $e) {
            $this->assertStringContainsString('insufficient paper balance', $e->getMessage());
        }

        $this->assertEqualsWithDelta(10000.0, $this->exchange->balance('USDT'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->exchange->balance('BTC'), 1e-9);
        $this->assertSame(0, PaperBalance::query()->where('asset', 'BTC')->count());
    }

    public function test_selling_more_base_than_held_throws(): void
    {
        $this->exchange->placeOrder(new OrderRequest('BTCUSDT', OrderSide::Buy, 10.0));

        $this->expectException(ExchangeException::class);

        $this->exchange->placeOrder(new OrderRequest('BTCUSDT', OrderSide::Sell, 10.5));
    }

    public function test_non_positive_quantity_is_rejected(): void
    {
        $this->expectException(ExchangeException::class);

        $this->exchange->placeOrder(new OrderRequest('BTCUSDT', OrderSide::Buy, 0.0));
    }

    public function test_maker_entries_and_bnb_discount_reduce_fees(): void
    {
        $exchange = new PaperExchange(
            $this->marketData,
            [
                'starting_balance' => 10000.0,
                'fee_rate' => 0.001,
                'maker_fee_rate' => 0.00075,
                'maker_entries' => true,
                'fee_bnb_discount' => true,
                'slippage_bps' => 0.0,
            ],
            'USDT',
        );

        // Entry (buy) at maker rate with BNB discount: 0.00075 * 0.75 * 1000.
        $buy = $exchange->placeOrder(new OrderRequest('BTCUSDT', OrderSide::Buy, 10.0));
        $this->assertEqualsWithDelta(0.5625, $buy->fee, 1e-9);

        // Exit (sell) stays taker, discounted: 0.001 * 0.75 * 1000.
        $sell = $exchange->placeOrder(new OrderRequest('BTCUSDT', OrderSide::Sell, 10.0));
        $this->assertEqualsWithDelta(0.75, $sell->fee, 1e-9);
    }
}
