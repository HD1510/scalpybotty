<?php

namespace App\Models;

use App\Trading\Enums\TradingMode;
use Illuminate\Database\Eloquent\Model;

class EquitySnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mode' => TradingMode::class,
            'equity' => 'float',
            'quote_balance' => 'float',
            'unrealized_pnl' => 'float',
        ];
    }
}
