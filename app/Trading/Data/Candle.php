<?php

namespace App\Trading\Data;

/**
 * A single OHLCV candlestick. Times are unix milliseconds (UTC).
 */
final readonly class Candle
{
    public function __construct(
        public int $openTime,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume,
        public int $closeTime,
    ) {
    }

    public function isBullish(): bool
    {
        return $this->close > $this->open;
    }

    /** Typical price (HLC/3), used e.g. for VWAP. */
    public function typicalPrice(): float
    {
        return ($this->high + $this->low + $this->close) / 3;
    }
}
