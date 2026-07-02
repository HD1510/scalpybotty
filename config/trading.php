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

    // Comma-separated list in .env, e.g. "BTCUSDT,ETHUSDT"
    'symbols' => array_filter(array_map('trim', explode(',', env('TRADING_SYMBOLS', 'BTCUSDT')))),

    // Candle interval the strategy runs on. Below 5m, round-trip taker fees
    // tend to exceed the ATR-based take-profit distance — backtest first.
    'interval' => env('TRADING_INTERVAL', '5m'),

    // Quote asset all balances and PnL are denominated in.
    'quote_asset' => env('TRADING_QUOTE_ASSET', 'USDT'),

    // Strategy to run; must be a key of 'strategies' below.
    'strategy' => env('TRADING_STRATEGY', 'ema_rsi_scalp'),

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
