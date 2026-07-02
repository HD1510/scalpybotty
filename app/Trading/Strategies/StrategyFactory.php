<?php

namespace App\Trading\Strategies;

use App\Trading\Contracts\Strategy;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Builds strategy instances from config. Each entry under
 * config('trading.strategies') carries its implementation in a 'class' key,
 * so registering a new strategy is a single config edit.
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
            throw new InvalidArgumentException(
                "Unknown trading strategy [{$name}]. Add a 'strategies.{$name}' entry (including its 'class') to config/trading.php.",
            );
        }

        $class = $params['class'] ?? config("trading.strategies.{$name}.class");

        if (! is_string($class) || ! is_a($class, Strategy::class, true)) {
            throw new InvalidArgumentException(
                "Strategy [{$name}] has no valid 'class' entry in config/trading.php (must implement ".Strategy::class.').',
            );
        }

        return new $class(Arr::except($params, 'class'));
    }
}
