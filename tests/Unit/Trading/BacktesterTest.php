<?php

namespace Tests\Unit\Trading;

use App\Trading\Backtest\Backtester;
use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\Signal;
use App\Trading\Enums\SignalAction;
use PHPUnit\Framework\TestCase;

final class BacktesterTest extends TestCase
{
    private const STARTING_BALANCE = 10_000.0;

    private const RISK_CONFIG = [
        'risk_per_trade' => 0.01,
        'max_open_trades' => 2,
        'max_daily_loss_pct' => 1.0,
        'min_confidence' => 0.5,
    ];

    private const PAPER_CONFIG = [
        'starting_balance' => 10_000.0,
        'fee_rate' => 0.001,
        'slippage_bps' => 0.0,
    ];

    public function testEntryExecutesAtNextCandleOpenAfterSignal(): void
    {
        // Rising series: candle j opens at 100 + j and closes at 100.5 + j.
        $candles = [];

        for ($j = 0; $j < 10; $j++) {
            $candles[] = $this->candle($j, 100.0 + $j, 101.5 + $j, 99.0 + $j, 100.5 + $j);
        }

        // Stop/TP far outside the traded range, so the position survives to
        // the end of the data.
        $backtester = new Backtester(
            $this->buyOnceStrategy(buyAtCount: 5, stop: 90.0, tp: 200.0),
            self::RISK_CONFIG,
            self::PAPER_CONFIG,
        );

        $result = $backtester->run($candles, self::STARTING_BALANCE);

        self::assertSame(1, $result->totalTrades);

        $trade = $result->trades[0];

        // Signal is emitted on candle index 4 (5 candles visible); the fill
        // must land on candle 5's open — not on the signal bar's close (104.5)
        // or open (104).
        self::assertEqualsWithDelta($candles[5]->open, $trade['entry'], 1e-9);
        self::assertSame($candles[5]->openTime, $trade['entry_time']);
        self::assertNotEqualsWithDelta($candles[4]->close, $trade['entry'], 1e-9);
        self::assertSame('end_of_data', $trade['reason']);
    }

    public function testCandleTouchingStopExitsAtStopPrice(): void
    {
        $candles = $this->flatCandlesWithShock(shockLow: 94.0, shockHigh: 101.0);

        $backtester = new Backtester(
            $this->buyOnceStrategy(buyAtCount: 5, stop: 95.0, tp: 200.0),
            self::RISK_CONFIG,
            self::PAPER_CONFIG,
        );

        $result = $backtester->run($candles, self::STARTING_BALANCE);

        self::assertSame(1, $result->totalTrades);

        $trade = $result->trades[0];

        self::assertSame('stop_loss', $trade['reason']);
        self::assertEqualsWithDelta(95.0, $trade['exit'], 1e-9);

        // Entry 100, qty = (0.01 * 10000) / (100 - 95) = 20.
        self::assertEqualsWithDelta(20.0, $trade['qty'], 1e-9);
        // PnL = (95 - 100) * 20 - entry fee 2.0 - exit fee 1.9.
        self::assertEqualsWithDelta(-103.9, $trade['pnl'], 1e-6);
    }

    public function testStopWinsWhenSameCandleReachesStopAndTakeProfit(): void
    {
        // The shock candle spans both the stop (95) and the take-profit (105);
        // the pessimistic fill model must choose the stop-loss.
        $candles = $this->flatCandlesWithShock(shockLow: 90.0, shockHigh: 110.0);

        $backtester = new Backtester(
            $this->buyOnceStrategy(buyAtCount: 5, stop: 95.0, tp: 105.0),
            self::RISK_CONFIG,
            self::PAPER_CONFIG,
        );

        $result = $backtester->run($candles, self::STARTING_BALANCE);

        self::assertSame(1, $result->totalTrades);
        self::assertSame('stop_loss', $result->trades[0]['reason']);
        self::assertEqualsWithDelta(95.0, $result->trades[0]['exit'], 1e-9);
    }

    public function testBalanceInvariantFeesAndDrawdownBounds(): void
    {
        $candles = $this->flatCandlesWithShock(shockLow: 94.0, shockHigh: 101.0);

        $backtester = new Backtester(
            $this->buyOnceStrategy(buyAtCount: 5, stop: 95.0, tp: 200.0),
            self::RISK_CONFIG,
            self::PAPER_CONFIG,
        );

        $result = $backtester->run($candles, self::STARTING_BALANCE);

        self::assertEqualsWithDelta(
            $result->startingBalance + $result->netPnl,
            $result->endingBalance,
            1e-6,
        );
        self::assertGreaterThan(0.0, $result->totalFees);
        self::assertGreaterThanOrEqual(0.0, $result->maxDrawdownPct);
        self::assertLessThanOrEqual(1.0, $result->maxDrawdownPct);
    }

    public function testAllHoldStrategyProducesNoTradesAndKeepsBalance(): void
    {
        $candles = [];

        for ($j = 0; $j < 10; $j++) {
            $candles[] = $this->candle($j, 100.0, 100.5, 99.5, 100.0);
        }

        $holdStrategy = new class implements Strategy {
            public function name(): string
            {
                return 'always_hold';
            }

            public function warmupPeriod(): int
            {
                return 3;
            }

            public function evaluate(array $candles): Signal
            {
                return Signal::hold('never trades');
            }
        };

        $backtester = new Backtester($holdStrategy, self::RISK_CONFIG, self::PAPER_CONFIG);

        $result = $backtester->run($candles, self::STARTING_BALANCE);

        self::assertSame(0, $result->totalTrades);
        self::assertSame([], $result->trades);
        self::assertEqualsWithDelta(self::STARTING_BALANCE, $result->endingBalance, 1e-9);
        self::assertEqualsWithDelta(0.0, $result->totalFees, 1e-9);
    }

    /**
     * Strategy stub: emits exactly one Buy with fixed stop/tp when it sees
     * exactly $buyAtCount candles, Hold otherwise.
     */
    private function buyOnceStrategy(int $buyAtCount, float $stop, float $tp): Strategy
    {
        return new class($buyAtCount, $stop, $tp) implements Strategy {
            public function __construct(
                private int $buyAtCount,
                private float $stop,
                private float $tp,
            ) {
            }

            public function name(): string
            {
                return 'buy_once_stub';
            }

            public function warmupPeriod(): int
            {
                return 3;
            }

            public function evaluate(array $candles): Signal
            {
                if (count($candles) === $this->buyAtCount) {
                    return new Signal(SignalAction::Buy, 0.9, $this->stop, $this->tp, 'stub buy');
                }

                return Signal::hold();
            }
        };
    }

    /**
     * Ten flat candles around 100 with a configurable shock candle at index 6,
     * one bar after the entry fill on candle 5's open (100.0).
     *
     * @return Candle[]
     */
    private function flatCandlesWithShock(float $shockLow, float $shockHigh): array
    {
        $candles = [];

        for ($j = 0; $j < 10; $j++) {
            $candles[] = $j === 6
                ? $this->candle($j, 100.0, $shockHigh, $shockLow, 96.0)
                : $this->candle($j, 100.0, 100.5, 99.5, 100.0);
        }

        return $candles;
    }

    private function candle(int $index, float $open, float $high, float $low, float $close): Candle
    {
        return new Candle(
            openTime: $index * 60_000,
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            volume: 10.0,
            closeTime: $index * 60_000 + 59_999,
        );
    }
}
