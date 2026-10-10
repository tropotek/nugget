<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The exchange's own id of the last trade the sync has read. The next
     * sync asks for the trades after it; null means from the first.
     */
    public function up(): void
    {
        Schema::table('exchanges', function (Blueprint $table) {
            $table->string('trade_cursor')->nullable()->after('last_sync_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchanges', function (Blueprint $table) {
            $table->dropColumn('trade_cursor');
        });
    }
};
