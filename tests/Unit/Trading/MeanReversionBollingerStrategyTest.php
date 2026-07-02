<?php

namespace Tests\Unit\Trading;

use App\Trading\Data\Candle;
use App\Trading\Enums\SignalAction;
use App\Trading\Strategies\MeanReversionBollingerStrategy;
use PHPUnit\Framework\TestCase;

class MeanReversionBollingerStrategyTest extends TestCase
{
    private const PARAMS = [
        'entry_confirmation' => true,
        'trend_ema' => 0,
        'bb_period' => 20,
        'bb_std_dev' => 2.0,
        'rsi_period' => 14,
        'rsi_oversold' => 30.0,
        'atr_period' => 14,
        'atr_stop_mult' => 1.5,
        'min_tp_atr' => 0.1,
    ];

    public function test_confirmation_mode_holds_while_price_is_still_below_the_band(): void
    {
        $strategy = new MeanReversionBollingerStrategy(self::PARAMS);

        // Flat series, then a sharp dip on the LAST candle: still falling.
        $candles = $this->dipSeries(recovered: false);

        $this->assertSame(SignalAction::Hold, $strategy->evaluate($candles)->action);
    }

    public function test_confirmation_mode_buys_when_the_close_turns_back_above_the_band(): void
    {
        $strategy = new MeanReversionBollingerStrategy(self::PARAMS);

        $candles = $this->dipSeries(recovered: true);
        $signal = $strategy->evaluate($candles);

        $this->assertSame(SignalAction::Buy, $signal->action);
        $this->assertNotNull($signal->stopLoss);
        $this->assertNotNull($signal->takeProfit);
        $this->assertLessThan(end($candles)->close, $signal->stopLoss);
        $this->assertGreaterThan(end($candles)->close, $signal->takeProfit);
    }

    public function test_touch_mode_buys_while_price_is_below_the_band(): void
    {
        $strategy = new MeanReversionBollingerStrategy(['entry_confirmation' => false] + self::PARAMS);

        $signal = $strategy->evaluate($this->dipSeries(recovered: false));

        $this->assertSame(SignalAction::Buy, $signal->action);
    }

    public function test_trend_filter_suppresses_dip_buys_below_the_trend_ema(): void
    {
        // EMA(5) hugs the recent prices; right after the dip the close sits
        // below it, so the regime filter must suppress the entry.
        $strategy = new MeanReversionBollingerStrategy(['trend_ema' => 5] + self::PARAMS);

        $signal = $strategy->evaluate($this->dipSeries(recovered: true));

        $this->assertSame(SignalAction::Hold, $signal->action);
        $this->assertStringContainsString('trend EMA', $signal->reason);
    }

    public function test_insufficient_candles_hold(): void
    {
        $strategy = new MeanReversionBollingerStrategy(self::PARAMS);

        $this->assertSame(SignalAction::Hold, $strategy->evaluate([])->action);
    }

    /**
     * 25 flat candles at 100, then a dip to 92; optionally one more candle
     * closing back above the (widened) lower band at 97.
     *
     * @return Candle[]
     */
    private function dipSeries(bool $recovered): array
    {
        $closes = array_fill(0, 25, 100.0);
        $closes[] = 92.0;

        if ($recovered) {
            $closes[] = 97.0;
        }

        $candles = [];
        $previous = $closes[0];

        foreach ($closes as $index => $close) {
            $candles[] = new Candle(
                openTime: $index * 60_000,
                open: $previous,
                high: max($previous, $close) + 0.1,
                low: min($previous, $close) - 0.1,
                close: $close,
                volume: 10.0,
                closeTime: $index * 60_000 + 59_999,
            );
            $previous = $close;
        }

        return $candles;
    }
}
