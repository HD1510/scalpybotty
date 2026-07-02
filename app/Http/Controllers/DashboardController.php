<?php

namespace App\Http\Controllers;

use App\Models\BotEvent;
use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Trading\Enums\TradeStatus;
use App\Trading\Enums\TradingMode;
use Illuminate\Contracts\View\View;

/**
 * Read-only bot dashboard. Deliberately never talks to the exchange so it
 * loads instantly and works offline — everything shown comes from the
 * database the bot loop maintains.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $mode = TradingMode::from((string) config('trading.mode'));

        $snapshots = EquitySnapshot::query()
            ->where('mode', $mode)
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->reverse()
            ->values();

        $stats = Trade::query()
            ->where('mode', $mode)
            ->where('status', TradeStatus::Closed)
            ->selectRaw(
                'COUNT(*) as closed_count, '
                    .'COALESCE(SUM(pnl), 0) as total_pnl, '
                    .'COALESCE(SUM(entry_fee + exit_fee), 0) as total_fees, '
                    .'SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END) as wins, '
                    .'COALESCE(SUM(CASE WHEN closed_at >= ? THEN pnl ELSE 0 END), 0) as today_pnl',
                [now('UTC')->startOfDay()]
            )
            ->first();

        $closedCount = (int) $stats->closed_count;

        return view('dashboard', [
            'mode' => $mode,
            'equitySeries' => $snapshots->map(fn (EquitySnapshot $s) => [
                't' => $s->created_at?->toIso8601String(),
                'v' => round($s->equity, 2),
            ])->all(),
            'latestEquity' => $snapshots->last()?->equity,
            'todayPnl' => (float) $stats->today_pnl,
            'totalPnl' => (float) $stats->total_pnl,
            'totalFees' => (float) $stats->total_fees,
            'closedCount' => $closedCount,
            'winRate' => $closedCount === 0 ? null : (int) $stats->wins / $closedCount,
            'openTrades' => Trade::query()
                ->where('mode', $mode)
                ->where('status', TradeStatus::Open)
                ->orderByDesc('opened_at')
                ->get(),
            'events' => BotEvent::query()
                ->where('mode', $mode)
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'tradeMarkers' => Trade::query()
                ->where('mode', $mode)
                ->where('status', TradeStatus::Closed)
                ->whereNotNull('closed_at')
                ->orderByDesc('closed_at')
                ->limit(200)
                ->get()
                ->map(fn (Trade $t) => [
                    't' => $t->closed_at?->toIso8601String(),
                    'pnl' => $t->pnl,
                    'symbol' => $t->symbol,
                    'reason' => $t->close_reason,
                ])
                ->values()
                ->all(),
            'recentTrades' => Trade::query()
                ->where('mode', $mode)
                ->where('status', TradeStatus::Closed)
                ->orderByDesc('closed_at')
                ->limit(20)
                ->get(),
            'quoteAsset' => (string) config('trading.quote_asset'),
        ]);
    }
}
