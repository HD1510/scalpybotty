<?php

namespace App\Trading\Enums;

enum SignalAction: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case Hold = 'hold';
}
