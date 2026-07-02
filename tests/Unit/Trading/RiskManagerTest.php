<?php

namespace Tests\Unit\Trading;

use App\Models\Trade;
use App\Trading\Data\Signal;
use App\Trading\Enums\SignalAction;
use App\Trading\Enums\TradingMode;
use App\Trading\Risk\RiskManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskManagerTest extends TestCase
{
    use RefreshDatabase;

    private RiskManager $riskManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->riskManager = new RiskManager([
            'risk_per_trade' => 0.01,
            'max_open_trades' => 2,
            'max_daily_loss_pct' => 0.03,
            'min_confidence' => 0.5,
        ]);
    }

    public function test_position_size_uses_fixed_fractional_risk(): void
    {
        // Risk 1% of 10000 = 100 over a per-unit risk of 2 => 50 units.
        $qty = $this->riskManager->positionSize(10000.0, 10000.0, 100.0, 98.0);

        $this->assertEqualsWithDelta(50.0, $qty, 1e-9);
    }

    public function test_position_size_is_capped_by_available_quote(): void
    {
        // Tight stop: 100 / 0.1 = 1000 units would cost 100000; cap at
        // 99% of available quote / entry price instead.
        $qty = $this->riskManager->positionSize(10000.0, 10000.0, 100.0, 99.9);

        $this->assertEqualsWithDelta((10000.0 * 0.99) / 100.0, $qty, 1e-9);
    }

    public function test_position_size_is_zero_when_stop_not_below_entry(): void
    {
        $this->assertSame(0.0, $this->riskManager->positionSize(10000.0, 10000.0, 100.0, 100.0));
        $this->assertSame(0.0, $this->riskManager->positionSize(10000.0, 10000.0, 100.0, 101.0));
    }

    public function test_position_size_is_zero_without_equity_or_quote(): void
    {
        $this->assertSame(0.0, $this->riskManager->positionSize(0.0, 10000.0, 100.0, 98.0));
        $this->assertSame(0.0, $this->riskManager->positionSize(10000.0, 0.0, 100.0, 98.0));
    }

    public function test_passes_confidence_compares_against_minimum(): void
    {
        $this->assertTrue($this->riskManager->passesConfidence(
            new Signal(SignalAction::Buy, confidence: 0.5),
        ));
        $this->assertFalse($this->riskManager->passesConfidence(
            new Signal(SignalAction::Buy, confidence: 0.49),
        ));
    }

    public function test_entries_are_allowed_with_clean_database(): void
    {
        $this->assertNull($this->riskManager->entryBlockReason(TradingMode::Paper, 10000.0));
    }

    public function test_open_trade_cap_blocks_entries(): void
    {
        $this->createOpenTrade(TradingMode::Paper);
        $this->createOpenTrade(TradingMode::Paper);

        $this->assertNotNull($this->riskManager->entryBlockReason(TradingMode::Paper, 10000.0));
    }

    public function test_daily_loss_limit_blocks_entries(): void
    {
        // Realized -400 today at equity 10000 exceeds the 3% (300) limit.
        $this->createClosedTrade(TradingMode::Paper, -250.0, now('UTC'));
        $this->createClosedTrade(TradingMode::Paper, -150.0, now('UTC'));

        $this->assertNotNull($this->riskManager->entryBlockReason(TradingMode::Paper, 10000.0));
    }

    public function test_losses_before_utc_midnight_do_not_block_entries(): void
    {
        $yesterday = now('UTC')->startOfDay()->subHour();

        $this->createClosedTrade(TradingMode::Paper, -250.0, $yesterday);
        $this->createClosedTrade(TradingMode::Paper, -150.0, $yesterday);

        $this->assertNull($this->riskManager->entryBlockReason(TradingMode::Paper, 10000.0));
    }

    public function test_open_live_trades_do_not_block_paper_entries(): void
    {
        $this->createOpenTrade(TradingMode::Live);
        $this->createOpenTrade(TradingMode::Live);

        $this->assertNull($this->riskManager->entryBlockReason(TradingMode::Paper, 10000.0));
    }

    public function test_recent_stop_loss_triggers_entry_cooldown(): void
    {
        $manager = $this->managerWithCooldown(30);
        $this->createStopLossTrade(now('UTC')->subMinutes(10));

        $this->assertStringContainsString(
            'cooldown after stop-loss',
            (string) $manager->entryBlockReason(TradingMode::Paper, 10000.0, 'BTCUSDT'),
        );
    }

    public function test_expired_cooldown_allows_entries_again(): void
    {
        $manager = $this->managerWithCooldown(30);
        $this->createStopLossTrade(now('UTC')->subMinutes(40));

        $this->assertNull($manager->entryBlockReason(TradingMode::Paper, 10000.0, 'BTCUSDT'));
    }

    public function test_cooldown_is_per_symbol_and_ignores_other_exit_reasons(): void
    {
        $manager = $this->managerWithCooldown(30);
        $this->createStopLossTrade(now('UTC')->subMinutes(10));
        $this->createClosedTrade(TradingMode::Paper, 50.0, now('UTC')->subMinutes(5)); // no close_reason

        $this->assertNull($manager->entryBlockReason(TradingMode::Paper, 10000.0, 'ETHUSDT'));
    }

    private function managerWithCooldown(int $minutes): RiskManager
    {
        return new RiskManager([
            'risk_per_trade' => 0.01,
            'max_open_trades' => 2,
            'max_daily_loss_pct' => 1.0,
            'min_confidence' => 0.5,
            'entry_cooldown_minutes' => $minutes,
        ]);
    }

    private function createStopLossTrade(\Illuminate\Support\Carbon $closedAt): Trade
    {
        $trade = $this->createClosedTrade(TradingMode::Paper, -100.0, $closedAt);
        $trade->update(['close_reason' => 'stop_loss']);

        return $trade;
    }

    private function createOpenTrade(TradingMode $mode): Trade
    {
        return Trade::create([
            'symbol' => 'BTCUSDT',
            'status' => 'open',
            'mode' => $mode->value,
            'strategy' => 'ema_rsi_scalp',
            'quantity' => 1.0,
            'entry_price' => 100.0,
            'stop_loss' => 98.0,
            'take_profit' => 105.0,
            'opened_at' => now('UTC'),
        ]);
    }

    private function createClosedTrade(TradingMode $mode, float $pnl, \Illuminate\Support\Carbon $closedAt): Trade
    {
        return Trade::create([
            'symbol' => 'BTCUSDT',
            'status' => 'closed',
            'mode' => $mode->value,
            'strategy' => 'ema_rsi_scalp',
            'quantity' => 1.0,
            'entry_price' => 100.0,
            'exit_price' => 100.0 + $pnl,
            'stop_loss' => 98.0,
            'take_profit' => 105.0,
            'pnl' => $pnl,
            'opened_at' => $closedAt->copy()->subHour(),
            'closed_at' => $closedAt,
        ]);
    }
}
