<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a sale needs so that a rematch gives it the same parcels again.
     *
     * transactions.matching is the method a sale was matched by. parcel_picks
     * holds the parcels a sale was matched to by hand. A pick names a parcel
     * by the transaction that acquired it and its acquired date, because a
     * rematch gives every parcel a new id.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('matching', 16)->nullable()->after('estimated');
        });

        Schema::create('parcel_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->dateTime('acquired_at');
            $table->string('units', 40);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcel_picks');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('matching');
        });
    }
};
