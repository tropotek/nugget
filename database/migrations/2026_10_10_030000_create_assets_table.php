<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A thing a user holds. It is priced by a market on one of the user's
     * exchanges (exchange_id and market_id), by a feed symbol, or by nothing.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('symbol', 32);
            $table->string('class', 16)->index();
            $table->string('unit', 16);
            $table->foreignId('exchange_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('market_id', 64)->nullable();
            $table->string('feed_symbol', 64)->nullable();
            $table->boolean('in_total')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
