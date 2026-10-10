<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's account on an exchange. api_key and secret hold encrypted text,
     * and aud_balance is a fixed-scale decimal string.
     */
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('driver', 64);
            $table->string('label');
            $table->text('api_key')->nullable();
            $table->text('secret')->nullable();
            $table->string('aud_balance', 32)->default('0.00');
            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
