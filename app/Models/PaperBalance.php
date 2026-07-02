<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperBalance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }
}
