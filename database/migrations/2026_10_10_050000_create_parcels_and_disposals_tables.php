<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both tables are worked out from the transactions by the parcel matcher.
     *
     * A parcel is a lot of units acquired together, with the AUD cost of the
     * whole lot. A disposal is the part of one parcel that one sale used up.
     * Units and AUD amounts are fixed-scale decimal strings.
     */
    public function up(): void
    {
        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->dateTime('acquired_at');
            $table->string('units', 40);
            $table->string('units_remaining', 40);
            $table->string('cost_base', 32);
            $table->boolean('estimated')->default(false);
            $table->timestamps();

            $table->index(['asset_id', 'acquired_at']);
        });

        Schema::create('disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('parcel_id')->constrained()->restrictOnDelete();
            $table->string('units', 40);
            $table->string('cost_base', 32);
            $table->string('proceeds', 32);
            $table->string('gain', 32);
            $table->boolean('long_held')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disposals');
        Schema::dropIfExists('parcels');
    }
};
