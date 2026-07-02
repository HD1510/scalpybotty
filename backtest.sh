#!/usr/bin/env bash
#
# Komplettes Backtest-Protokoll über beide Strategien und mehrere Timeframes:
#   ./backtest.sh                    # BTCUSDT, 90 Tage, 5m/15m/1h
#   ./backtest.sh ETHUSDT            # anderes Symbol
#   ./backtest.sh BTCUSDT 60 "5m 1h" # eigene Spanne/Intervalle
#
# Für jede Kombination laufen drei Zeitfenster:
#   full = gesamte Historie | in = bis vor SPLIT_DAYS (zum Tunen)
#   out  = die letzten SPLIT_DAYS, die ein Optimizer nie gesehen hat —
#          NUR dieses Fenster zählt für die Bewertung einer Strategie.
#
# Daten werden nur exportiert, wenn die CSV fehlt (FORCE_EXPORT=1 erzwingt).
# Volle Reports: storage/app/backtests/

set -euo pipefail
cd "$(dirname "$0")"

SYMBOL="${1:-BTCUSDT}"
DAYS="${2:-90}"
INTERVALS="${3:-5m 15m 1h}"
SPLIT_DAYS="${SPLIT_DAYS:-30}"
STRATEGIES="${STRATEGIES:-ema_rsi_scalp bollinger_reversion}"

SPLIT_DATE=$(date -u -d "-${SPLIT_DAYS} days" +%F 2>/dev/null || date -u -v-"${SPLIT_DAYS}"d +%F)
REPORT_DIR="storage/app/backtests"
STAMP=$(date -u +%Y%m%d-%H%M%S)
mkdir -p "$REPORT_DIR"

metric() { # metric <logfile> <label>  -> Wert aus der Report-Tabelle
    grep -F "| $2" "$1" 2>/dev/null | head -1 | awk -F'|' '{gsub(/^ +| +$/,"",$3); print $3}'
}

SUMMARY=$(printf '%-22s %-5s %-5s %8s %9s %6s %10s %8s' 'Strategie' 'TF' 'Fens.' 'Trades' 'Winrate' 'PF' 'NetPnL%' 'MaxDD%')

run_case() { # run_case <strategy> <interval> <window-label> <extra-args...>
    local strat="$1" iv="$2" label="$3"; shift 3
    local log="$REPORT_DIR/$STAMP-$strat-$iv-$label.txt"

    if TRADING_STRATEGY="$strat" php artisan bot:backtest \
        --csv="storage/app/candles/$SYMBOL-$iv.csv" "$@" > "$log" 2>&1; then
        SUMMARY+=$'\n'$(printf '%-22s %-5s %-5s %8s %9s %6s %10s %8s' \
            "$strat" "$iv" "$label" \
            "$(metric "$log" 'Total trades')" \
            "$(metric "$log" 'Win rate')" \
            "$(metric "$log" 'Profit factor')" \
            "$(metric "$log" 'Net PnL %')" \
            "$(metric "$log" 'Max drawdown')")
    else
        SUMMARY+=$'\n'$(printf '%-22s %-5s %-5s %s' "$strat" "$iv" "$label" "FEHLER — siehe $log")
    fi
}

for iv in $INTERVALS; do
    csv="storage/app/candles/$SYMBOL-$iv.csv"

    if [[ ! -f "$csv" || "${FORCE_EXPORT:-0}" == "1" ]]; then
        echo "==> Exportiere $SYMBOL $iv ($DAYS Tage)..."
        php artisan bot:export-data --symbol="$SYMBOL" --interval="$iv" --days="$DAYS" --out="$csv"
    else
        echo "==> $csv vorhanden — Export übersprungen (FORCE_EXPORT=1 erzwingt)"
    fi

    for strat in $STRATEGIES; do
        echo "==> $strat auf $iv (full / in-sample / out-of-sample)..."
        run_case "$strat" "$iv" "full"
        run_case "$strat" "$iv" "in" --to="$SPLIT_DATE"
        run_case "$strat" "$iv" "out" --from="$SPLIT_DATE"
    done
done

echo
echo "================================================================================"
echo "  Backtest-Zusammenfassung  $SYMBOL  ($DAYS Tage, Split: $SPLIT_DATE)"
echo "  'out' = die letzten $SPLIT_DAYS Tage — das einzige Fenster, das zählt."
echo "================================================================================"
echo "$SUMMARY"
echo
echo "Volle Reports: $REPORT_DIR/$STAMP-*.txt"
echo "Nächster Schritt bei einem vielversprechenden 'in'-Fenster:"
echo "  TRADING_STRATEGY=<strat> php artisan bot:optimize --csv=storage/app/candles/$SYMBOL-<tf>.csv --to=$SPLIT_DATE"
echo "  ... und die beste Kombination dann mit --from=$SPLIT_DATE gegenvalidieren."
