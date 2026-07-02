# scalpybotty

Ein Crypto-Scalping-Bot auf Laravel-Basis. Handelt Spot-Märkte auf Binance
(Mainnet oder Testnet) mit einer EMA-Crossover-Strategie, RSI-Momentum-Filter
und ATR-basierten Stops — standardmäßig im **Paper-Modus**: echte Marktdaten,
simulierte Orders, kein echtes Geld.

> **Risiko-Warnung:** Kein Trading-Bot ist garantiert profitabel — auch dieser
> nicht. Scalping ist wegen Gebühren und Slippage besonders unversöhnlich.
> Lass den Bot ausgiebig im Paper-Modus und Backtest laufen, bevor du auch nur
> daran denkst, `TRADING_MODE=live` zu setzen. Handle nie mit Geld, dessen
> Verlust du dir nicht leisten kannst.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Für den Paper-Modus ist **kein API-Key nötig** — Marktdaten kommen über die
öffentliche Binance-API. Für Live-Trading (oder das Binance-Testnet) trägst du
`BINANCE_API_KEY` / `BINANCE_API_SECRET` in `.env` ein.

## Benutzung

```bash
# Strategie gegen echte historische Daten testen (empfohlener erster Schritt)
php artisan bot:backtest --symbol=BTCUSDT --days=14

# Historische Daten einmalig als CSV exportieren ...
php artisan bot:export-data --symbol=BTCUSDT --days=30

# ... und Backtests danach beliebig oft offline fahren (z.B. beim Parameter-Tuning)
php artisan bot:backtest --csv=storage/app/candles/BTCUSDT-5m.csv

# Bot im Paper-Modus laufen lassen (Standard: alle 30s ein Tick)
php artisan bot:run

# Ein einzelner Evaluierungs-Zyklus
php artisan bot:run --once

# Offene Positionen, PnL und Equity anzeigen
php artisan bot:status
```

**Praxis-Erkenntnis aus dem Backtest:** Auf 1m-Candles übersteigen die
Round-Trip-Taker-Gebühren (2×0,1%) typischerweise die ATR-basierte
Take-Profit-Distanz — die Strategie verliert dann strukturell, egal wie gut
die Signale sind. Default ist deshalb `5m`. Prüfe nach jedem Parameter-Tuning
im Report, dass `Total fees` klein gegenüber `Gross profit` bleibt.

## Konfiguration

Alles Wichtige liegt in `config/trading.php` bzw. `.env`:

| Variable | Default | Bedeutung |
|---|---|---|
| `TRADING_MODE` | `paper` | `paper` = simulierte Orders, `live` = echtes Geld |
| `TRADING_SYMBOLS` | `BTCUSDT` | Kommagetrennte Handelspaare |
| `TRADING_INTERVAL` | `1m` | Candle-Intervall der Strategie |
| `TRADING_STRATEGY` | `ema_rsi_scalp` | Strategie-Key aus `config/trading.php` |
| `TRADING_RISK_PER_TRADE` | `0.01` | Anteil der Equity, der pro Trade riskiert wird |
| `TRADING_MAX_OPEN_TRADES` | `3` | Max. gleichzeitig offene Positionen |
| `TRADING_MAX_DAILY_LOSS_PCT` | `0.03` | Tages-Verlustlimit — danach öffnet der Bot keine neuen Trades mehr (Circuit Breaker) |
| `TRADING_PAPER_BALANCE` | `10000` | Startguthaben (Quote-Asset) im Paper-Modus |
| `BINANCE_TESTNET` | `true` | Binance Spot-Testnet statt Mainnet verwenden |

Die Strategie-Parameter (EMA-Perioden, RSI-Band, ATR-Multiplikatoren) stehen
unter `strategies.ema_rsi_scalp` in `config/trading.php`.

## Architektur

```
app/Trading/
├── Contracts/      Exchange, Strategy, HistoricalDataProvider
├── Data/           DTOs: Candle, Ticker, Signal, OrderRequest/Result, SymbolMeta
├── Enums/          OrderSide, SignalAction, TradeStatus, TradingMode
├── Exchanges/      BinanceExchange (REST, signiert), PaperExchange (Simulation)
├── Indicators/     EMA, SMA, RSI (Wilder), ATR, Bollinger, VWAP
├── Strategies/     EmaRsiScalpStrategy
├── Risk/           RiskManager: Position-Sizing, Trade-Limits, Circuit Breaker
├── Bot/            TradingBot: der Tick-Loop (Entry/Exit/SL/TP)
└── Backtest/       Backtester + BacktestResult
```

Zentrale Design-Entscheidungen:

- **Stop-Loss/Take-Profit verwaltet der Bot selbst** (kein Exchange-OCO),
  damit sich Paper- und Live-Modus identisch verhalten.
- **Strategien sind stateless** und sehen ausschließlich abgeschlossene
  Candles — dieselbe Klasse läuft unverändert im Backtest und live.
- **Der Backtester ist look-ahead-frei:** Signale werden auf Candle *i*
  berechnet, ausgeführt wird zur Eröffnung von Candle *i+1*, inklusive
  Slippage und Gebühren; bei SL und TP in derselben Candle gewinnt
  pessimistisch der Stop.
- **Risk-Management sitzt vor jedem Entry:** Fixed-Fractional-Sizing,
  Max-Positions-Limit und ein tägliches Verlustlimit als Circuit Breaker.

Trades, Orders und Equity-Verlauf werden in SQLite persistiert
(`trades`, `orders`, `equity_snapshots`, `paper_balances`).

## Tests

```bash
php artisan test
```

## Eigene Strategie schreiben

1. Klasse unter `app/Trading/Strategies/` anlegen, die
   `App\Trading\Contracts\Strategy` implementiert.
2. Parameter-Block unter `strategies.<name>` in `config/trading.php` ergänzen.
3. Die Klasse im `match` in `App\Providers\TradingServiceProvider` registrieren.
4. `TRADING_STRATEGY=<name>` setzen — Backtest zuerst!
