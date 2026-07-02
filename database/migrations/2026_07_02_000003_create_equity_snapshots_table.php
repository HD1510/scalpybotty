<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('mode'); // paper|live
            // Total account value in quote asset (free balance + open position value).
            $table->double('equity');
            $table->double('quote_balance');
            $table->double('unrealized_pnl')->default(0);
            $table->unsignedInteger('open_trades')->default(0);
            $table->timestamps();

            $table->index(['mode', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_snapshots');
    }
};
