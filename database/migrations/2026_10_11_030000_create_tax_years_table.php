<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a user has entered for the tax report of one financial year: so
     * far, the capital losses carried forward into it from earlier years. The
     * year is the one it ends in (2025 is 2024–25), and the amount a
     * fixed-scale decimal string.
     */
    public function up(): void
    {
        Schema::create('tax_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('losses_carried_forward', 32)->default('0.00');
            $table->timestamps();

            $table->unique(['user_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_years');
    }
};
