<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('time_slot_id')
                ->unique()
                ->constrained('time_slots')
                ->restrictOnDelete();

            $table->foreignId('captain_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('captain_name');
            $table->string('captain_role', 50);
            $table->string('captain_phone');

            $table->decimal('total_price', 10, 2);

            $table->string('result')->nullable();

            $table->string('status')->default('pending');

            $table->string('share_token')->unique();

            $table->timestamps();

            $table->index('captain_user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
