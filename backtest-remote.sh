#!/usr/bin/env bash
#
# Auf dem LOKALEN Rechner (Mac) ausführen:
#   ./backtest-remote.sh                              # BTC/ETH/SOL, 90 Tage, 5m/15m/1h
#   ./backtest-remote.sh "BTCUSDT ETHUSDT" 60 "1h"    # gleiche Argumente wie backtest.sh
#
# Startet ./backtest.sh per SSH auf dem Server und synchronisiert danach
# alle Reports nach LOCAL_DIR. Umgebungsvariablen wie FORCE_EXPORT=1 oder
# SPLIT_DAYS=14 werden an den Server durchgereicht.

set -euo pipefail

SERVER="${SERVER:-forge@209.38.180.43}"
REMOTE_DIR="${REMOTE_DIR:-scalpybotty}"
LOCAL_DIR="${LOCAL_DIR:-$HOME/backtests}"

mkdir -p "$LOCAL_DIR"

echo "==> Backtest auf $SERVER starten..."
ssh -t "$SERVER" "cd $REMOTE_DIR && \
    FORCE_EXPORT=${FORCE_EXPORT:-0} SPLIT_DAYS=${SPLIT_DAYS:-30} \
    ./backtest.sh \"${1:-BTCUSDT ETHUSDT SOLUSDT}\" ${2:-90} \"${3:-5m 15m 1h}\""

echo
echo "==> Reports nach $LOCAL_DIR synchronisieren..."
rsync -av --include='*.txt' --exclude='*' \
    "$SERVER:$REMOTE_DIR/storage/app/backtests/" "$LOCAL_DIR/"

echo
echo "Fertig — Reports liegen in $LOCAL_DIR"
ls -lt "$LOCAL_DIR" | head -8
