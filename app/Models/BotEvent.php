<?php

namespace App\Models;

use App\Trading\Enums\TradingMode;
use Illuminate\Database\Eloquent\Model;

class BotEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mode' => TradingMode::class,
        ];
    }
}
