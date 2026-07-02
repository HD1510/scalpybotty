<?php

namespace App\Models;

use App\Trading\Enums\TradeStatus;
use App\Trading\Enums\TradingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trade extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TradeStatus::class,
            'mode' => TradingMode::class,
            'quantity' => 'float',
            'entry_price' => 'float',
            'exit_price' => 'float',
            'stop_loss' => 'float',
            'take_profit' => 'float',
            'entry_fee' => 'float',
            'exit_fee' => 'float',
            'pnl' => 'float',
            'pnl_pct' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === TradeStatus::Open;
    }

    /** Unrealized PnL in quote asset at the given price, net of the entry fee. */
    public function unrealizedPnl(float $currentPrice): float
    {
        return ($currentPrice - $this->entry_price) * $this->quantity - $this->entry_fee;
    }
}
