<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an exchange said the account held at its last sync: a row for
     * each symbol with a balance, AUD among them. The rows are replaced at
     * every sync. units and available are fixed-scale decimal strings.
     *
     * last_sync_note on the exchange says which steps the last sync skipped
     * because the exchange's driver cannot do them.
     */
    public function up(): void
    {
        Schema::create('exchange_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('units', 40);
            $table->string('available', 40);
            $table->timestamps();

            $table->unique(['exchange_id', 'symbol']);
        });

        Schema::table('exchanges', function (Blueprint $table) {
            $table->text('last_sync_note')->nullable()->after('last_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchanges', function (Blueprint $table) {
            $table->dropColumn('last_sync_note');
        });

        Schema::dropIfExists('exchange_balances');
    }
};
