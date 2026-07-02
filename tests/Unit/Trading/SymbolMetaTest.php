<?php

namespace Tests\Unit\Trading;

use App\Trading\Data\SymbolMeta;
use PHPUnit\Framework\TestCase;

class SymbolMetaTest extends TestCase
{
    public function test_quantize_quantity_rounds_down_to_step_size(): void
    {
        $meta = $this->meta(stepSize: 0.00001);

        $this->assertEqualsWithDelta(0.12345, $meta->quantizeQuantity(0.123456789), 1e-12);
        $this->assertEqualsWithDelta(0.12345, $meta->quantizeQuantity(0.12345), 1e-12);
        $this->assertEqualsWithDelta(0.0, $meta->quantizeQuantity(0.000009), 1e-12);
    }

    public function test_quantize_quantity_returns_input_when_step_size_is_not_positive(): void
    {
        $meta = $this->meta(stepSize: 0.0);

        $this->assertSame(0.123456789, $meta->quantizeQuantity(0.123456789));
    }

    public function test_quantize_price_rounds_to_tick_size(): void
    {
        $meta = $this->meta(tickSize: 0.01);

        $this->assertEqualsWithDelta(123.46, $meta->quantizePrice(123.456), 1e-12);
        $this->assertEqualsWithDelta(123.45, $meta->quantizePrice(123.454), 1e-12);
        $this->assertEqualsWithDelta(123.45, $meta->quantizePrice(123.45), 1e-12);
    }

    public function test_quantize_price_returns_input_when_tick_size_is_not_positive(): void
    {
        $meta = $this->meta(tickSize: 0.0);

        $this->assertSame(123.456789, $meta->quantizePrice(123.456789));
    }

    public function test_meets_min_notional_boundary(): void
    {
        $meta = $this->meta(minNotional: 5.0);

        $this->assertTrue($meta->meetsMinNotional(2.0, 2.5));
        $this->assertTrue($meta->meetsMinNotional(3.0, 2.0));
        $this->assertFalse($meta->meetsMinNotional(2.0, 2.49));
    }

    private function meta(
        float $stepSize = 0.00001,
        float $tickSize = 0.01,
        float $minNotional = 5.0,
    ): SymbolMeta {
        return new SymbolMeta('BTCUSDT', 'BTC', 'USDT', $stepSize, $tickSize, $minNotional);
    }
}
