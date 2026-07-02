<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('status')->default('open'); // open|closed
            $table->string('mode'); // paper|live
            $table->string('strategy');
            $table->double('quantity');
            $table->double('entry_price');
            $table->double('exit_price')->nullable();
            $table->double('stop_loss');
            $table->double('take_profit');
            $table->double('entry_fee')->default(0);
            $table->double('exit_fee')->default(0);
            // Realized PnL in quote asset, net of fees. Null while open.
            $table->double('pnl')->nullable();
            $table->double('pnl_pct')->nullable();
            $table->string('close_reason')->nullable(); // stop_loss|take_profit|signal|manual
            $table->text('entry_reason')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'mode']);
            $table->index(['symbol', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
