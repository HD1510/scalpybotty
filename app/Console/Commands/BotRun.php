<?php

namespace App\Console\Commands;

use App\Trading\Bot\TradingBot;
use App\Trading\Risk\RiskManager;
use Illuminate\Console\Command;
use Throwable;

final class BotRun extends Command
{
    protected $signature = 'bot:run
        {--once : Run a single tick}
        {--sleep=30 : Seconds between ticks}
        {--live-confirmed : Acknowledge that live mode places real orders with real money}';

    protected $description = 'Run the trading bot loop over all configured symbols';

    public function handle(): int
    {
        if (config('trading.mode') === 'live' && ! $this->option('live-confirmed')) {
            $this->error('DANGER: trading.mode is "live" — this would place REAL orders with REAL money.');
            $this->error('Re-run with --live-confirmed if you are certain, or set TRADING_MODE=paper.');

            return self::FAILURE;
        }

        // Exchange and Strategy resolve via TradingServiceProvider; RiskManager
        // takes a plain config array the container cannot supply on its own.
        $bot = $this->laravel->make(TradingBot::class, [
            'risk' => new RiskManager(config('trading.risk')),
        ]);

        $sleep = max(0, (int) $this->option('sleep'));

        while (true) {
            try {
                foreach ($bot->tick() as $line) {
                    $this->line(sprintf('[%s] %s', now()->toDateTimeString(), $line));
                }
            } catch (Throwable $e) {
                $this->error(sprintf('[%s] tick failed: %s', now()->toDateTimeString(), $e->getMessage()));
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep($sleep);
        }
    }
}
