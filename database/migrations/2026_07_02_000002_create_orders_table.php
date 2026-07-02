<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->nullable()->constrained()->nullOnDelete();
            $table->string('exchange_order_id');
            $table->string('symbol');
            $table->string('side'); // buy|sell
            $table->string('mode'); // paper|live
            $table->string('status'); // filled|rejected
            $table->double('quantity');
            $table->double('average_price');
            $table->double('fee')->default(0);
            $table->string('fee_asset')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index('symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
