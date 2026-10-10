<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AUD price of one unit of an asset. bid and ask are fixed-scale
     * decimal strings.
     *
     * An intraday row is a price at a moment, and they are pruned. A day row
     * is the last price of a calendar day in the owner's timezone, kept for
     * good: its `at` is that date at 00:00:00.
     */
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->dateTime('at');
            $table->string('bid', 40);
            $table->string('ask', 40);
            $table->string('resolution', 16);
            $table->timestamps();

            $table->unique(['asset_id', 'resolution', 'at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
