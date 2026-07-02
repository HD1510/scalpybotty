<?php

namespace App\Trading\Data;

use App\Trading\Enums\OrderSide;

/**
 * A market order to be placed on an exchange.
 *
 * The bot only uses market orders; stop-loss and take-profit are managed by
 * the bot loop itself so behaviour is identical in paper and live mode.
 */
final readonly class OrderRequest
{
    public function __construct(
        public string $symbol,
        public OrderSide $side,
        /** Quantity in base asset, already quantized to the symbol's step size. */
        public float $quantity,
        public ?string $clientOrderId = null,
    ) {
    }
}
