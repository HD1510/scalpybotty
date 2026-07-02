# scalpybotty

Ein Crypto-Trading-Bot auf Laravel-Basis. Handelt Spot-Märkte auf Binance
(Mainnet oder Testnet), standardmäßig mit einer Bollinger-Mean-Reversion-
Strategie auf 1h-Candles (Einstiegs-Bestätigung, Trend-Filter, ATR-Stops);
ein EMA-Crossover-Scalper liegt als zweite Strategie bei. Default ist der
**Paper-Modus**: echte Marktdaten, simulierte Orders, kein echtes Geld.

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

# Strategie-Parameter per Grid-Search tunen (Ranking nach Netto-PnL)
php artisan bot:optimize --csv=storage/app/candles/BTCUSDT-5m.csv --param=fast_ema=5,9,12

# Bot im Paper-Modus laufen lassen (Standard: alle 30s ein Tick)
php artisan bot:run

# Ein einzelner Evaluierungs-Zyklus
php artisan bot:run --once

# Offene Positionen, PnL und Equity anzeigen
php artisan bot:status
```

Das **Dashboard** (Equity-Kurve, offene Positionen, letzte Trades) läuft unter
`/dashboard` — `php artisan serve` und im Browser öffnen.

**Praxis-Erkenntnis aus 90 Tagen Mainnet-Backtests:** Unterhalb von
1h-Candles übersteigen die Round-Trip-Taker-Gebühren (2×0,1%) die
erzielbaren Kursbewegungen — jede getestete Konfiguration verlor dort
strukturell, egal wie gut die Signale waren. Default ist deshalb
`bollinger_reversion` auf `1h` über mehrere Symbole (die einzige
Kombination, die out-of-sample bestand). Prüfe nach jedem
Parameter-Tuning im Report, dass `Total fees` klein gegenüber
`Gross profit` bleibt.

## Konfiguration

Alles Wichtige liegt in `config/trading.php` bzw. `.env`:

| Variable | Default | Bedeutung |
|---|---|---|
| `TRADING_MODE` | `paper` | `paper` = simulierte Orders, `live` = echtes Geld |
| `TRADING_SYMBOLS` | `BTCUSDT,ETHUSDT,SOLUSDT` | Kommagetrennte Handelspaare |
| `TRADING_INTERVAL` | `1h` | Candle-Intervall der Strategie |
| `TRADING_STRATEGY` | `bollinger_reversion` | Strategie-Key aus `config/trading.php` |
| `TRADING_RISK_PER_TRADE` | `0.01` | Anteil der Equity, der pro Trade riskiert wird |
| `TRADING_MAX_OPEN_TRADES` | `3` | Max. gleichzeitig offene Positionen |
| `TRADING_MAX_DAILY_LOSS_PCT` | `0.03` | Tages-Verlustlimit — danach öffnet der Bot keine neuen Trades mehr (Circuit Breaker) |
| `TRADING_PAPER_BALANCE` | `10000` | Startguthaben (Quote-Asset) im Paper-Modus |
| `TRADING_LIVE_CONFIRMED` | `false` | Zweiter Faktor für Live-Trading: ohne dieses Flag (oder `bot:run --live-confirmed`) verweigert der Bot echte Orders — egal, wo er aufgerufen wird |
| `BINANCE_TESTNET` | `true` | Binance Spot-Testnet statt Mainnet verwenden |

Die Strategie-Parameter (Bollinger-Band, RSI-Schwellen, Trend-EMA,
ATR-Multiplikatoren) stehen unter `strategies.*` in `config/trading.php`.

## Architektur

```
app/Trading/
├── Contracts/      Exchange, Strategy, HistoricalDataProvider
├── Data/           DTOs: Candle, Ticker, Signal, OrderRequest/Result, SymbolMeta
├── Enums/          OrderSide, SignalAction, TradeStatus, TradingMode
├── Exchanges/      BinanceExchange (REST, signiert), PaperExchange (Simulation)
├── Indicators/     EMA, SMA, RSI (Wilder), ATR, Bollinger, VWAP
├── Strategies/     EmaRsiScalp, MeanReversionBollinger, DonchianBreakout
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
2. Block unter `strategies.<name>` in `config/trading.php` ergänzen —
   inklusive `'class' => DeineStrategie::class` und der Parameter.
3. `TRADING_STRATEGY=<name>` setzen — Backtest zuerst!
