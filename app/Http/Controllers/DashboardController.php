<?php

namespace App\Http\Controllers;

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

        $closed = Trade::query()
            ->where('mode', $mode)
            ->where('status', TradeStatus::Closed)
            ->get();

        $todayPnl = (float) $closed
            ->filter(fn (Trade $t) => $t->closed_at?->gte(now('UTC')->startOfDay()))
            ->sum('pnl');

        $wins = $closed->where('pnl', '>', 0)->count();

        return view('dashboard', [
            'mode' => $mode,
            'equitySeries' => $snapshots->map(fn (EquitySnapshot $s) => [
                't' => $s->created_at?->toIso8601String(),
                'v' => round($s->equity, 2),
            ])->all(),
            'latestEquity' => $snapshots->last()?->equity,
            'todayPnl' => $todayPnl,
            'totalPnl' => (float) $closed->sum('pnl'),
            'totalFees' => (float) $closed->sum(fn (Trade $t) => $t->entry_fee + $t->exit_fee),
            'closedCount' => $closed->count(),
            'winRate' => $closed->isEmpty() ? null : $wins / $closed->count(),
            'openTrades' => Trade::query()
                ->where('mode', $mode)
                ->where('status', TradeStatus::Open)
                ->orderByDesc('opened_at')
                ->get(),
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
