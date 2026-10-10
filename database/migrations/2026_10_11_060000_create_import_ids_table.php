<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The map from a row of the tkbtc database to the Nugget row the import
     * made of it or matched it to. It is what lets the import be run again
     * and add nothing twice, and lets a later pass find what an earlier one
     * brought over.
     *
     * `created` is false where the row was already in Nugget (a user with
     * the same email), so that clearing an import does not delete it.
     */
    public function up(): void
    {
        Schema::create('import_ids', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('legacy_id', 64);
            $table->unsignedBigInteger('new_id');
            $table->boolean('created')->default(true);
            $table->timestamps();

            $table->unique(['type', 'legacy_id']);
            $table->index(['type', 'new_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_ids');
    }
};
