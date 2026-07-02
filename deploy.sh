#!/usr/bin/env bash
#
# Update & Neustart in einem Schritt:
#   ./deploy.sh
#
# Holt den neuesten Code, installiert Abhängigkeiten, migriert die DB,
# leert alle Laravel-Caches und startet Dashboard + Bot neu — danach
# läuft garantiert der frisch gepullte Stand.

set -euo pipefail
cd "$(dirname "$0")"

echo "==> Code aktualisieren"
git pull --ff-only
echo "    Stand: $(git log --oneline -1)"

echo "==> Abhängigkeiten installieren"
composer install --no-dev --no-interaction --prefer-dist --quiet

echo "==> Datenbank migrieren"
php artisan migrate --force

echo "==> Caches leeren (config/route/view)"
php artisan optimize:clear --quiet

echo "==> Prozesse neu starten"
if systemctl list-unit-files scalpy-bot.service >/dev/null 2>&1 \
    && systemctl list-unit-files scalpy-bot.service 2>/dev/null | grep -q scalpy-bot; then
    sudo systemctl restart scalpy-web scalpy-bot
    echo "    systemd: scalpy-web + scalpy-bot neu gestartet"
else
    pkill -f "artisan serve" 2>/dev/null || true
    pkill -f "artisan bot:run" 2>/dev/null || true
    sleep 1
    nohup php artisan serve --host=127.0.0.1 --port=8080 > storage/logs/http.log 2>&1 &
    nohup php artisan bot:run > storage/logs/bot.log 2>&1 &
    echo "    nohup: Dashboard (8080) + Bot-Loop neu gestartet"
fi

echo "==> Verifikation"
sleep 2
pgrep -af "artisan serve" >/dev/null && echo "    Dashboard: läuft" || echo "    Dashboard: FEHLT — storage/logs/http.log prüfen!"
pgrep -af "bot:run" >/dev/null && echo "    Bot:       läuft ($(php artisan tinker --execute="echo config('trading.mode');" 2>/dev/null || echo '?')-Modus)" || echo "    Bot:       FEHLT — storage/logs/bot.log prüfen!"

echo
echo "Fertig. Logs: tail -f storage/logs/bot.log | Status: php artisan bot:status"
