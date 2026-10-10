<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger: everything that happened to an asset. Holdings, parcels and
     * the tax report are all worked out from these rows.
     *
     * units, unit_price, fee and total are fixed-scale decimal strings. total
     * is the AUD cost with the fee for units coming in, and the AUD proceeds
     * less the fee for units going out.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->dateTime('occurred_at');
            $table->string('units', 40);
            $table->string('unit_price', 40)->default('0.00000000');
            $table->string('fee', 32)->default('0.00');
            $table->string('total', 32)->default('0.00');
            $table->string('source', 16)->default('manual');
            $table->string('external_id')->nullable();
            $table->boolean('estimated')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'occurred_at']);

            // Rows with no external_id never clash: a unique index treats each null as different.
            $table->unique(['asset_id', 'source', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
