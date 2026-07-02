<?php

namespace App\Trading\Strategies;

use App\Trading\Contracts\Strategy;
use InvalidArgumentException;

/**
 * Builds strategy instances by config key. The single place that maps
 * strategy names to classes — used by the service container, the backtest
 * command and the parameter optimizer.
 */
final class StrategyFactory
{
    /**
     * @param  array|null  $params  Overrides config('trading.strategies.<name>') when given —
     *                              the optimizer uses this to sweep parameter combinations.
     */
    public function make(string $name, ?array $params = null): Strategy
    {
        $params ??= config("trading.strategies.{$name}");

        if (! is_array($params) || $params === []) {
            throw new InvalidArgumentException("Unknown trading strategy [{$name}]. Add it to config/trading.php.");
        }

        return match ($name) {
            'ema_rsi_scalp' => new EmaRsiScalpStrategy($params),
            'bollinger_reversion' => new MeanReversionBollingerStrategy($params),
            default => throw new InvalidArgumentException("No implementation registered for strategy [{$name}]."),
        };
    }
}
