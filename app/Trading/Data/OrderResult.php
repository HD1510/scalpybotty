<?php

namespace App\Trading\Data;

use App\Trading\Enums\OrderSide;

/**
 * The result of a filled (or rejected) order. Timestamp is unix milliseconds (UTC).
 */
final readonly class OrderResult
{
    public function __construct(
        public string $orderId,
        public string $symbol,
        public OrderSide $side,
        /** 'filled' or 'rejected'. Market orders never rest on the book. */
        public string $status,
        public float $executedQuantity,
        /** Volume-weighted average fill price. */
        public float $averagePrice,
        /** Total fee, denominated in $feeAsset. */
        public float $fee,
        public string $feeAsset,
        public int $timestamp,
        /** Raw exchange response for debugging/audit. */
        public array $raw = [],
    ) {
    }

    public function isFilled(): bool
    {
        return $this->status === 'filled' && $this->executedQuantity > 0;
    }

    /** Order value in quote asset. */
    public function quoteValue(): float
    {
        return $this->executedQuantity * $this->averagePrice;
    }
}
