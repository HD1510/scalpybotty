<?php

namespace App\Trading\Enums;

enum TradeStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
