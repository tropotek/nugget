<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The moment an exchange's history has been brought in up to, from its
     * own transaction report. The trade sync stores only trades after it, so
     * that it does not add again what the report already gave.
     */
    public function up(): void
    {
        Schema::table('exchanges', function (Blueprint $table) {
            $table->dateTime('trades_after')->nullable()->after('trade_cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchanges', function (Blueprint $table) {
            $table->dropColumn('trades_after');
        });
    }
};
