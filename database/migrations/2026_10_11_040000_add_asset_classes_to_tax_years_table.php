<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The asset classes a user's tax report for the year takes in, as a JSON
     * list of App\Enums\AssetClass values. Null is every class.
     */
    public function up(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->json('asset_classes')->nullable()->after('losses_carried_forward');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropColumn('asset_classes');
        });
    }
};
