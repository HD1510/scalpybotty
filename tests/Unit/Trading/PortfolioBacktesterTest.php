<?php

namespace Tests\Unit\Trading;

use App\Trading\Backtest\PortfolioBacktester;
use App\Trading\Data\Candle;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PortfolioBacktesterTest extends TestCase
{
    private const CONFIG = [
        'lookback_days' => 5,
        'skip_days' => 1,
        'top_k' => 1,
        'rebalance_days' => 5,
        'min_momentum' => 0.0,
    ];

    public function test_rotation_holds_the_strongest_symbol_and_beats_the_flat_ones(): void
    {
        $series = [
            'UP' => $this->dailySeries(fn (int $d): float => 100.0 * (1.01 ** $d)),
            'FLAT' => $this->dailySeries(fn (): float => 100.0),
            'DOWN' => $this->dailySeries(fn (int $d): float => 100.0 * (0.99 ** $d)),
        ];

        $result = (new PortfolioBacktester(self::CONFIG, 0.001))->run($series, 10000.0);

        $this->assertGreaterThan(10000.0, $result['endingBalance']);
        $this->assertSame(['UP' => 1.0], $result['lastAllocation']);
        $this->assertGreaterThan(0.0, $result['totalFees']);
        $this->assertGreaterThan($result['benchmarkReturnPct'], $result['totalReturnPct']);
    }

    public function test_absolute_momentum_stays_in_cash_when_everything_declines(): void
    {
        $series = [
            'A' => $this->dailySeries(fn (int $d): float => 100.0 * (0.99 ** $d)),
            'B' => $this->dailySeries(fn (int $d): float => 100.0 * (0.98 ** $d)),
        ];

        $result = (new PortfolioBacktester(self::CONFIG, 0.001))->run($series, 10000.0);

        $this->assertEqualsWithDelta(10000.0, $result['endingBalance'], 1e-9);
        $this->assertSame(0.0, $result['totalFees']);
        $this->assertSame($result['days'], $result['cashDays']);
        $this->assertSame([], $result['lastAllocation']);
    }

    public function test_lookback_must_exceed_skip(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PortfolioBacktester(['lookback_days' => 5, 'skip_days' => 5, 'top_k' => 1, 'rebalance_days' => 5, 'min_momentum' => 0.0], 0.001))
            ->run(['A' => $this->dailySeries(fn (): float => 100.0)], 10000.0);
    }

    /**
     * @param  callable(int): float  $close
     * @return Candle[]
     */
    private function dailySeries(callable $close, int $days = 30): array
    {
        $candles = [];

        for ($d = 0; $d < $days; $d++) {
            $price = $close($d);
            $candles[] = new Candle(
                openTime: $d * 86_400_000,
                open: $price,
                high: $price * 1.001,
                low: $price * 0.999,
                close: $price,
                volume: 100.0,
                closeTime: $d * 86_400_000 + 86_399_999,
            );
        }

        return $candles;
    }
}
