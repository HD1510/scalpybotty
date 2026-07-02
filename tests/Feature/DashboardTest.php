<?php

namespace Tests\Feature;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_renders_with_empty_database(): void
    {
        $this->get('/dashboard')
            ->assertStatus(200)
            ->assertSee('scalpybotty')
            ->assertSee('Keine offenen Positionen');
    }

    public function test_dashboard_shows_trades_and_equity(): void
    {
        config(['trading.mode' => 'paper']);

        EquitySnapshot::create([
            'mode' => 'paper',
            'equity' => 10123.45,
            'quote_balance' => 10000.0,
            'unrealized_pnl' => 123.45,
            'open_trades' => 1,
        ]);

        Trade::create([
            'symbol' => 'BTCUSDT', 'status' => 'open', 'mode' => 'paper',
            'strategy' => 'ema_rsi_scalp', 'quantity' => 0.05,
            'entry_price' => 64000.0, 'stop_loss' => 63500.0, 'take_profit' => 65000.0,
            'opened_at' => now('UTC'),
        ]);

        Trade::create([
            'symbol' => 'BTCUSDT', 'status' => 'closed', 'mode' => 'paper',
            'strategy' => 'ema_rsi_scalp', 'quantity' => 0.05,
            'entry_price' => 63000.0, 'exit_price' => 63500.0,
            'stop_loss' => 62500.0, 'take_profit' => 63500.0,
            'entry_fee' => 3.15, 'exit_fee' => 3.18, 'pnl' => 18.67, 'pnl_pct' => 0.0059,
            'close_reason' => 'take_profit',
            'opened_at' => now('UTC')->subHour(), 'closed_at' => now('UTC'),
        ]);

        $this->get('/dashboard')
            ->assertStatus(200)
            ->assertSee('10,123.45')
            ->assertSee('BTCUSDT')
            ->assertSee('take_profit');
    }
}
