<?php

namespace App\Trading\Data;

/**
 * Last traded price for a symbol. Timestamp is unix milliseconds (UTC).
 */
final readonly class Ticker
{
    public function __construct(
        public string $symbol,
        public float $price,
        public int $timestamp,
    ) {
    }
}
