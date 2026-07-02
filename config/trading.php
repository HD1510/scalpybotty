<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trading mode
    |--------------------------------------------------------------------------
    |
    | 'paper': real market data, simulated fills and balances. Safe default.
    | 'live':  real orders with real money on the configured exchange.
    |          Only ever enable this after extensive paper testing.
    |
    */

    'mode' => env('TRADING_MODE', 'paper'),

    // Second factor for live trading: even in live mode, orders are refused
    // unless this is true (set it, or pass --live-confirmed to bot:run).
    'live_confirmed' => (bool) env('TRADING_LIVE_CONFIRMED', false),

    /*
    |--------------------------------------------------------------------------
    | Market selection
    |--------------------------------------------------------------------------
    */

    'exchange' => env('TRADING_EXCHANGE', 'binance'),

    // Comma-separated list in .env. The default strategy trades roughly once
    // a month per symbol, so several symbols keep the bot meaningfully busy.
    'symbols' => array_filter(array_map('trim', explode(',', env('TRADING_SYMBOLS', 'BTCUSDT,ETHUSDT,SOLUSDT')))),

    // Candle interval the strategy runs on. 90-day mainnet backtests showed
    // nothing survives round-trip taker fees below 1h — backtest first.
    'interval' => env('TRADING_INTERVAL', '1h'),

    // Quote asset all balances and PnL are denominated in.
    'quote_asset' => env('TRADING_QUOTE_ASSET', 'USDT'),

    // Strategy to run; must be a key of 'strategies' below. The EMA scalper
    // remains available for experiments but lost decisively on every
    // timeframe in 90-day backtests — bollinger_reversion on 1h was the
    // only configuration that held up out-of-sample.
    'strategy' => env('TRADING_STRATEGY', 'bollinger_reversion'),

    // How many candles to fetch per evaluation. Must exceed the strategy's warmup.
    'candle_limit' => (int) env('TRADING_CANDLE_LIMIT', 150),

    /*
    |--------------------------------------------------------------------------
    | Risk management
    |--------------------------------------------------------------------------
    |
    | risk_per_trade:     fraction of current equity risked per trade
    |                     (distance to stop-loss, not position size).
    | max_open_trades:    hard cap on concurrently open positions.
    | max_daily_loss_pct: circuit breaker — once today's realized losses
    |                     exceed this fraction of equity, no new trades are
    |                     opened until the next UTC day.
    | min_confidence:     signals below this confidence are ignored.
    |
    */

    'risk' => [
        'risk_per_trade' => (float) env('TRADING_RISK_PER_TRADE', 0.01),
        'max_open_trades' => (int) env('TRADING_MAX_OPEN_TRADES', 3),
        'max_daily_loss_pct' => (float) env('TRADING_MAX_DAILY_LOSS_PCT', 0.03),
        'min_confidence' => (float) env('TRADING_MIN_CONFIDENCE', 0.5),
        // After a stop-loss, block re-entry on that symbol for this many
        // minutes (0 disables) — no immediate re-buys into a falling market.
        'entry_cooldown_minutes' => (int) env('TRADING_ENTRY_COOLDOWN_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paper trading
    |--------------------------------------------------------------------------
    */

    'paper' => [
        // Starting balance in quote asset, credited on first run.
        'starting_balance' => (float) env('TRADING_PAPER_BALANCE', 10000.0),
        // Taker fee per order (0.001 = 0.1%, Binance default).
        'fee_rate' => (float) env('TRADING_PAPER_FEE_RATE', 0.001),
        // Maker fee per order (Binance spot default 0.075% for limit fills).
        'maker_fee_rate' => (float) env('TRADING_PAPER_MAKER_FEE_RATE', 0.00075),
        // Charge ENTRIES at the maker rate. Models placing the entry as a
        // limit order at the signal price. OPTIMISTIC assumption: every limit
        // is assumed filled — in reality some entries would be missed, so
        // treat results as an upper bound of the maker advantage.
        'maker_entries' => (bool) env('TRADING_PAPER_MAKER_ENTRIES', false),
        // 25% fee discount for paying fees in BNB (applies to both sides).
        'fee_bnb_discount' => (bool) env('TRADING_FEE_BNB_DISCOUNT', false),
        // Simulated slippage in basis points applied against the trader.
        'slippage_bps' => (float) env('TRADING_PAPER_SLIPPAGE_BPS', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy parameters
    |--------------------------------------------------------------------------
    */

    'strategies' => [
        'ema_rsi_scalp' => [
            'class' => App\Trading\Strategies\EmaRsiScalpStrategy::class,
            'fast_ema' => 9,
            'slow_ema' => 21,
            'rsi_period' => 14,
            // Long entries require RSI above this (momentum filter) ...
            'rsi_entry_min' => 50.0,
            // ... but below this (overbought filter).
            'rsi_entry_max' => 70.0,
            'atr_period' => 14,
            // Stop-loss distance = atr_stop_mult * ATR below entry.
            'atr_stop_mult' => 1.5,
            // Take-profit distance = atr_tp_mult * ATR above entry.
            'atr_tp_mult' => 2.5,
        ],

        'bollinger_reversion' => [
            'class' => App\Trading\Strategies\MeanReversionBollingerStrategy::class,
            // Wait for the close to turn back above the lower band before
            // entering (avoids buying into a falling knife).
            'entry_confirmation' => true,
            // Only fade dips above this EMA (0 disables the regime filter).
            'trend_ema' => 200,
            'bb_period' => 20,
            'bb_std_dev' => 2.0,
            'rsi_period' => 14,
            // Long entries require RSI at or below this (oversold confirmation).
            'rsi_oversold' => 30.0,
            'atr_period' => 14,
            // Stop-loss distance = atr_stop_mult * ATR below entry.
            'atr_stop_mult' => 1.5,
            // Take-profit is the middle band (mean reversion target); this is
            // the minimum distance in ATRs it must be above entry to bother.
            'min_tp_atr' => 0.5,
        ],

        // Aggressive turtle-style momentum breakout: many small stop-outs,
        // the occasional large trend ride. Complements bollinger_reversion,
        // which only trades calm dips.
        'donchian_breakout' => [
            'class' => App\Trading\Strategies\DonchianBreakoutStrategy::class,
            // Only take breakouts above this EMA (0 disables the regime
            // filter). Tested over 365d on BTC/ETH/SOL: helped BTC, cut off
            // the profitable trades on ETH/SOL — net negative, so off by
            // default; kept as an option for other markets.
            'trend_ema' => 0,
            // Entry: close breaks the highest high of this many candles.
            'donchian_period' => 20,
            // Exit: close falls chandelier_mult ATRs below the highest close
            // of the last exit_period candles.
            'exit_period' => 10,
            'chandelier_mult' => 3.0,
            'atr_period' => 14,
            'atr_stop_mult' => 2.0,
            // Far target — winners are meant to run into the chandelier exit.
            'atr_tp_mult' => 6.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-sectional momentum (portfolio rotation)
    |--------------------------------------------------------------------------
    |
    | Rank a universe of coins by trailing return, hold the top_k equally
    | weighted, rebalance every rebalance_days. Momentum is measured from
    | lookback_days ago to skip_days ago (skipping the most recent days
    | avoids short-term reversal). With min_momentum, coins must trend at
    | least this much to be held at all — otherwise that slot stays in cash
    | (absolute momentum filter).
    |
    */

    'xs_momentum' => [
        'universe' => array_filter(array_map('trim', explode(',', env(
            'TRADING_XSMOM_UNIVERSE',
            'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT,ADAUSDT,DOGEUSDT,LINKUSDT,LTCUSDT,DOTUSDT,AVAXUSDT,UNIUSDT',
        )))),
        'lookback_days' => (int) env('TRADING_XSMOM_LOOKBACK', 30),
        'skip_days' => (int) env('TRADING_XSMOM_SKIP', 7),
        'top_k' => (int) env('TRADING_XSMOM_TOP', 3),
        'rebalance_days' => (int) env('TRADING_XSMOM_REBALANCE', 7),
        'min_momentum' => (float) env('TRADING_XSMOM_MIN_MOMENTUM', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange credentials & endpoints
    |--------------------------------------------------------------------------
    */

    'binance' => [
        'key' => env('BINANCE_API_KEY', ''),
        'secret' => env('BINANCE_API_SECRET', ''),
        // Use the Binance Spot testnet (https://testnet.binance.vision).
        'testnet' => (bool) env('BINANCE_TESTNET', true),
        'base_url' => env('BINANCE_BASE_URL', 'https://api.binance.com'),
        'testnet_base_url' => env('BINANCE_TESTNET_BASE_URL', 'https://testnet.binance.vision'),
        // Request timeout in seconds.
        'timeout' => (int) env('BINANCE_TIMEOUT', 10),
        'recv_window' => (int) env('BINANCE_RECV_WINDOW', 5000),
    ],

];
