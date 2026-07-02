<?php

namespace App\Models;

use App\Trading\Enums\OrderSide;
use App\Trading\Enums\TradingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'mode' => TradingMode::class,
            'quantity' => 'float',
            'average_price' => 'float',
            'fee' => 'float',
            'raw' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
