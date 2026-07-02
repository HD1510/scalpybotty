<?php

namespace App\Trading\Data;

use App\Trading\Enums\SignalAction;

/**
 * A strategy's verdict for the current market state.
 *
 * For Buy signals, stopLoss and takeProfit are absolute prices the bot will
 * enforce; both must be set. Confidence is 0.0–1.0 and is compared against
 * config('trading.risk.min_confidence') before a trade is opened.
 */
final readonly class Signal
{
    public function __construct(
        public SignalAction $action,
        public float $confidence = 0.0,
        public ?float $stopLoss = null,
        public ?float $takeProfit = null,
        /** Human-readable explanation, persisted with the trade for later analysis. */
        public string $reason = '',
    ) {
    }

    public static function hold(string $reason = ''): self
    {
        return new self(SignalAction::Hold, 0.0, null, null, $reason);
    }
}
