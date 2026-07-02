<?php

namespace App\Trading\Data;

/**
 * Exchange trading rules for a symbol (precision and minimum order size).
 */
final readonly class SymbolMeta
{
    public function __construct(
        public string $symbol,
        public string $baseAsset,
        public string $quoteAsset,
        /** Quantity must be a multiple of this (e.g. 0.00001 BTC). */
        public float $stepSize,
        /** Price must be a multiple of this (e.g. 0.01 USDT). */
        public float $tickSize,
        /** Minimum order value in quote asset (e.g. 5 USDT). */
        public float $minNotional,
    ) {
    }

    /** Round a quantity DOWN to the symbol's step size. */
    public function quantizeQuantity(float $quantity): float
    {
        if ($this->stepSize <= 0) {
            return $quantity;
        }

        $precision = max(0, (int) round(-log10($this->stepSize)));

        // The epsilon counters float division artifacts: without it,
        // 20.0 / 0.00001 = 1999999.9999999998 floors to 19.99999.
        return round(floor($quantity / $this->stepSize + 1e-9) * $this->stepSize, $precision);
    }

    /** Round a price to the symbol's tick size. */
    public function quantizePrice(float $price): float
    {
        if ($this->tickSize <= 0) {
            return $price;
        }

        $precision = max(0, (int) round(-log10($this->tickSize)));

        return round(round($price / $this->tickSize) * $this->tickSize, $precision);
    }

    /** Whether an order of the given quantity at the given price meets the exchange minimum. */
    public function meetsMinNotional(float $quantity, float $price): bool
    {
        return $quantity * $price >= $this->minNotional;
    }
}
