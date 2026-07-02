<?php

namespace App\Trading\Support;

/**
 * Numeric formatting shared by console output, blade views and the
 * Binance API layer — one definition so quantities render identically
 * everywhere (no grouping separators: '1000.5', never '1,000.5').
 */
final class Num
{
    public static function trim(float $value, int $decimals = 8): string
    {
        $formatted = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
