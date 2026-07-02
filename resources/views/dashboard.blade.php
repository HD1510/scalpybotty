<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>scalpybotty — Dashboard</title>
<style>
:root {
    --surface-1: #fcfcfb;
    --page: #f9f9f7;
    --text-primary: #0b0b0b;
    --text-secondary: #52514e;
    --text-muted: #898781;
    --gridline: #e1e0d9;
    --baseline: #c3c2b7;
    --series-1: #2a78d6;
    --delta-good: #006300;
    --delta-bad: #d03b3b;
    --border: rgba(11, 11, 11, 0.10);
}
@media (prefers-color-scheme: dark) {
    :root {
        --surface-1: #1a1a19;
        --page: #0d0d0d;
        --text-primary: #ffffff;
        --text-secondary: #c3c2b7;
        --text-muted: #898781;
        --gridline: #2c2c2a;
        --baseline: #383835;
        --series-1: #3987e5;
        --delta-good: #0ca30c;
        --delta-bad: #d03b3b;
        --border: rgba(255, 255, 255, 0.10);
    }
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--page);
    color: var(--text-primary);
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    font-size: 14px;
    line-height: 1.45;
}
.wrap { max-width: 1080px; margin: 0 auto; padding: 24px 20px 48px; }
header { display: flex; align-items: baseline; gap: 12px; margin-bottom: 20px; }
header h1 { font-size: 20px; margin: 0; }
.mode-badge {
    font-size: 12px; font-weight: 600; padding: 2px 10px; border-radius: 999px;
    border: 1px solid var(--border); color: var(--text-secondary);
}
.mode-badge.live { color: var(--delta-bad); border-color: var(--delta-bad); }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
.tile {
    background: var(--surface-1); border: 1px solid var(--border);
    border-radius: 10px; padding: 14px 16px;
}
.tile .label { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
.tile .value { font-size: 22px; font-weight: 650; }
.tile .value.good { color: var(--delta-good); }
.tile .value.bad { color: var(--delta-bad); }
.panel {
    background: var(--surface-1); border: 1px solid var(--border);
    border-radius: 10px; padding: 16px; margin-bottom: 20px;
}
.panel h2 { font-size: 14px; margin: 0 0 12px; color: var(--text-secondary); font-weight: 600; }
#chart-box { position: relative; }
#chart-box svg { display: block; width: 100%; height: auto; }
#tooltip {
    position: absolute; pointer-events: none; display: none;
    background: var(--surface-1); border: 1px solid var(--border); border-radius: 8px;
    padding: 6px 10px; font-size: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.12);
    white-space: nowrap; transform: translate(-50%, -130%);
}
#tooltip .tt-v { font-weight: 650; }
#tooltip .tt-t { color: var(--text-muted); }
table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
th, td { text-align: right; padding: 6px 10px; border-bottom: 1px solid var(--gridline); }
th { color: var(--text-muted); font-size: 12px; font-weight: 600; }
th:first-child, td:first-child { text-align: left; }
tr:last-child td { border-bottom: none; }
td.good { color: var(--delta-good); }
td.bad { color: var(--delta-bad); }
.empty { color: var(--text-muted); padding: 24px 0; text-align: center; }
.axis-label { fill: var(--text-muted); font-size: 11px; font-family: system-ui, sans-serif; }
details summary { cursor: pointer; color: var(--text-muted); font-size: 12px; margin-top: 8px; }
</style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>scalpybotty</h1>
        <span class="mode-badge {{ $mode->value === 'live' ? 'live' : '' }}">{{ strtoupper($mode->value) }}</span>
        <span style="color: var(--text-muted); font-size: 12px;">{{ config('trading.strategy') }} · {{ implode(', ', config('trading.symbols')) }} · {{ config('trading.interval') }}</span>
    </header>

    <div class="tiles">
        <div class="tile">
            <div class="label">Equity</div>
            <div class="value">{{ $latestEquity !== null ? number_format($latestEquity, 2) : '—' }} <small>{{ $quoteAsset }}</small></div>
        </div>
        <div class="tile">
            <div class="label">PnL heute (realisiert)</div>
            <div class="value {{ $todayPnl > 0 ? 'good' : ($todayPnl < 0 ? 'bad' : '') }}">{{ sprintf('%+.2f', $todayPnl) }}</div>
        </div>
        <div class="tile">
            <div class="label">PnL gesamt</div>
            <div class="value {{ $totalPnl > 0 ? 'good' : ($totalPnl < 0 ? 'bad' : '') }}">{{ sprintf('%+.2f', $totalPnl) }}</div>
        </div>
        <div class="tile">
            <div class="label">Winrate ({{ $closedCount }} Trades)</div>
            <div class="value">{{ $winRate !== null ? sprintf('%.1f%%', $winRate * 100) : '—' }}</div>
        </div>
        <div class="tile">
            <div class="label">Gebühren gesamt</div>
            <div class="value">{{ number_format($totalFees, 2) }}</div>
        </div>
    </div>

    <div class="panel">
        <h2>Equity-Verlauf ({{ count($equitySeries) }} Snapshots)</h2>
        @if (count($equitySeries) >= 2)
            <div id="chart-box">
                <svg id="equity-chart" viewBox="0 0 860 240" role="img" aria-label="Equity-Verlauf in {{ $quoteAsset }}"></svg>
                <div id="tooltip"><span class="tt-v"></span><br><span class="tt-t"></span></div>
            </div>
            <details>
                <summary>Als Tabelle anzeigen</summary>
                <table>
                    <thead><tr><th>Zeit (UTC)</th><th>Equity ({{ $quoteAsset }})</th></tr></thead>
                    <tbody>
                    @foreach (array_slice(array_reverse($equitySeries), 0, 50) as $point)
                        <tr><td>{{ $point['t'] }}</td><td>{{ number_format($point['v'], 2) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        @else
            <div class="empty">Noch keine Equity-Snapshots — lass den Bot laufen: <code>php artisan bot:run</code></div>
        @endif
    </div>

    <div class="panel">
        <h2>Offene Positionen</h2>
        @if ($openTrades->isEmpty())
            <div class="empty">Keine offenen Positionen — der Bot ist flat.</div>
        @else
            <table>
                <thead><tr><th>Symbol</th><th>Menge</th><th>Entry</th><th>Stop-Loss</th><th>Take-Profit</th><th>Offen seit (UTC)</th></tr></thead>
                <tbody>
                @foreach ($openTrades as $trade)
                    <tr>
                        <td>{{ $trade->symbol }}</td>
                        <td>{{ \App\Trading\Support\Num::trim($trade->quantity) }}</td>
                        <td>{{ number_format($trade->entry_price, 4) }}</td>
                        <td>{{ number_format($trade->stop_loss, 4) }}</td>
                        <td>{{ number_format($trade->take_profit, 4) }}</td>
                        <td>{{ $trade->opened_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <h2>Bot-Aktivität (letzte {{ count($events) }} Meldungen)</h2>
        @if ($events->isEmpty())
            <div class="empty">Noch keine Meldungen — der Bot schreibt hier bei jedem Tick mit, sobald er läuft.</div>
        @else
            <table>
                <thead><tr><th>Zeit (UTC)</th><th style="text-align:left">Meldung</th></tr></thead>
                <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td style="white-space:nowrap">{{ $event->created_at?->format('H:i:s') }}</td>
                        <td style="text-align:left" class="{{ $event->level === 'error' ? 'bad' : '' }}">
                            @if ($event->level === 'warning')<span style="color:var(--delta-bad)">&#9888;</span>@endif
                            {{ $event->message }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <h2>Letzte Trades</h2>
        @if ($recentTrades->isEmpty())
            <div class="empty">Noch keine abgeschlossenen Trades.</div>
        @else
            <table>
                <thead><tr><th>Symbol</th><th>Entry</th><th>Exit</th><th>Menge</th><th>PnL ({{ $quoteAsset }})</th><th>Grund</th><th>Geschlossen (UTC)</th></tr></thead>
                <tbody>
                @foreach ($recentTrades as $trade)
                    <tr>
                        <td>{{ $trade->symbol }}</td>
                        <td>{{ number_format($trade->entry_price, 4) }}</td>
                        <td>{{ $trade->exit_price !== null ? number_format($trade->exit_price, 4) : '—' }}</td>
                        <td>{{ \App\Trading\Support\Num::trim($trade->quantity) }}</td>
                        <td class="{{ $trade->pnl > 0 ? 'good' : ($trade->pnl < 0 ? 'bad' : '') }}">{{ sprintf('%+.2f', $trade->pnl ?? 0) }}</td>
                        <td>{{ $trade->close_reason }}</td>
                        <td>{{ $trade->closed_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

<script>
(function () {
    const series = @json($equitySeries);
    if (series.length < 2) return;

    const svg = document.getElementById('equity-chart');
    const tooltip = document.getElementById('tooltip');
    const NS = 'http://www.w3.org/2000/svg';
    const W = 860, H = 240, PAD = { top: 12, right: 16, bottom: 26, left: 64 };
    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;

    const values = series.map(p => p.v);
    let min = Math.min(...values), max = Math.max(...values);
    if (min === max) { min -= 1; max += 1; }
    const span = max - min;
    min -= span * 0.05; max += span * 0.05;

    const x = i => PAD.left + (i / (series.length - 1)) * innerW;
    const y = v => PAD.top + (1 - (v - min) / (max - min)) * innerH;
    const el = (name, attrs) => {
        const node = document.createElementNS(NS, name);
        for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
        svg.appendChild(node);
        return node;
    };
    const css = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    // recessive horizontal gridlines + tick labels
    const ticks = 4;
    for (let t = 0; t <= ticks; t++) {
        const v = min + (t / ticks) * (max - min);
        const yy = y(v);
        el('line', { x1: PAD.left, x2: W - PAD.right, y1: yy, y2: yy, stroke: css('--gridline'), 'stroke-width': 1 });
        const label = el('text', { x: PAD.left - 8, y: yy + 4, 'text-anchor': 'end', class: 'axis-label' });
        label.style.fontVariantNumeric = 'tabular-nums';
        // no digit grouping: "10.007" would read as a decimal at this magnitude
        label.textContent = v.toLocaleString('de-DE', { maximumFractionDigits: 0, useGrouping: false });
    }

    // x-axis: first and last timestamp
    const fmt = iso => iso ? iso.slice(5, 16).replace('T', ' ') : '';
    const first = el('text', { x: PAD.left, y: H - 6, 'text-anchor': 'start', class: 'axis-label' });
    first.textContent = fmt(series[0].t);
    const last = el('text', { x: W - PAD.right, y: H - 6, 'text-anchor': 'end', class: 'axis-label' });
    last.textContent = fmt(series[series.length - 1].t);

    // the equity line — single series, 2px
    const d = series.map((p, i) => (i === 0 ? 'M' : 'L') + x(i).toFixed(2) + ' ' + y(p.v).toFixed(2)).join(' ');
    el('path', { d, fill: 'none', stroke: css('--series-1'), 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });

    // closed-trade markers on the curve: green = win, red = loss
    const markers = @json($tradeMarkers ?? []);
    const times = series.map(p => (p.t ? Date.parse(p.t) : 0));
    markers.forEach(m => {
        const mt = m.t ? Date.parse(m.t) : 0;
        if (!mt || mt < times[0] || mt > times[times.length - 1]) return;
        let best = 0, bestD = Infinity;
        times.forEach((t, i) => { const dd = Math.abs(t - mt); if (dd < bestD) { bestD = dd; best = i; } });
        const dot = el('circle', {
            cx: x(best), cy: y(series[best].v), r: 4,
            fill: (m.pnl ?? 0) >= 0 ? css('--delta-good') : css('--delta-bad'),
            stroke: css('--surface-1'), 'stroke-width': 2,
        });
        const title = document.createElementNS(NS, 'title');
        title.textContent = m.symbol + ' ' + (m.reason || '') + ' ' + ((m.pnl ?? 0) >= 0 ? '+' : '') + (m.pnl ?? 0).toFixed(2);
        dot.appendChild(title);
    });

    // hover layer: crosshair + marker + tooltip
    const crosshair = el('line', { x1: 0, x2: 0, y1: PAD.top, y2: H - PAD.bottom, stroke: css('--baseline'), 'stroke-width': 1, visibility: 'hidden' });
    const marker = el('circle', { r: 4, fill: css('--series-1'), stroke: css('--surface-1'), 'stroke-width': 2, visibility: 'hidden' });

    svg.addEventListener('mousemove', e => {
        const rect = svg.getBoundingClientRect();
        const px = (e.clientX - rect.left) * (W / rect.width);
        const i = Math.max(0, Math.min(series.length - 1, Math.round((px - PAD.left) / innerW * (series.length - 1))));
        const cx = x(i), cy = y(series[i].v);
        crosshair.setAttribute('x1', cx); crosshair.setAttribute('x2', cx);
        crosshair.setAttribute('visibility', 'visible');
        marker.setAttribute('cx', cx); marker.setAttribute('cy', cy);
        marker.setAttribute('visibility', 'visible');
        tooltip.style.display = 'block';
        tooltip.style.left = (cx / W * 100) + '%';
        tooltip.style.top = (cy / H * 100) + '%';
        tooltip.querySelector('.tt-v').textContent = series[i].v.toLocaleString('de-DE', { minimumFractionDigits: 2 }) + ' {{ $quoteAsset }}';
        tooltip.querySelector('.tt-t').textContent = fmt(series[i].t);
    });
    svg.addEventListener('mouseleave', () => {
        crosshair.setAttribute('visibility', 'hidden');
        marker.setAttribute('visibility', 'hidden');
        tooltip.style.display = 'none';
    });
})();
</script>
</body>
</html>
