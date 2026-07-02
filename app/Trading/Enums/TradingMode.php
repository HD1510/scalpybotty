<?php

namespace App\Trading\Enums;

enum TradingMode: string
{
    case Paper = 'paper';
    case Live = 'live';
}
