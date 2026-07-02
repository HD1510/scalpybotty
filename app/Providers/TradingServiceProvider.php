<?php

namespace App\Providers;

use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\Strategy;
use App\Trading\Enums\TradingMode;
use App\Trading\Exchanges\BinanceExchange;
use App\Trading\Exchanges\LiveGuardExchange;
use App\Trading\Exchanges\PaperExchange;
use App\Trading\Strategies\StrategyFactory;
use Illuminate\Support\ServiceProvider;

class TradingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BinanceExchange::class, function () {
            return new BinanceExchange(config('trading.binance'));
        });

        $this->app->singleton(Exchange::class, function ($app) {
            $mode = TradingMode::from(config('trading.mode'));

            if ($mode === TradingMode::Live) {
                // Real orders additionally require trading.live_confirmed.
                return new LiveGuardExchange($app->make(BinanceExchange::class));
            }

            // Paper mode: real market data from Binance, simulated fills.
            return new PaperExchange(
                $app->make(BinanceExchange::class),
                config('trading.paper'),
                config('trading.quote_asset'),
            );
        });

        $this->app->singleton(Strategy::class, function () {
            return (new StrategyFactory)->make((string) config('trading.strategy'));
        });
    }
}
