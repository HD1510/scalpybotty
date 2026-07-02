<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_events', function (Blueprint $table) {
            $table->id();
            $table->string('mode'); // paper|live
            $table->string('level')->default('info'); // info|warning|error
            $table->text('message');
            $table->timestamps();

            $table->index(['mode', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_events');
    }
};
