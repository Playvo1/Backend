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
        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('venue_id')
                ->constrained('venues')
                ->cascadeOnDelete();

            $table->foreignId('sport_id')
                ->constrained('sports')
                ->cascadeOnDelete();

            $table->date('slot_date');
            $table->time('start_time');
            $table->time('end_time');

            $table->decimal('hourly_price', 10, 2);

            $table->string('status')->default('available');

            $table->timestamps();

            $table->index(['venue_id', 'slot_date']);
            $table->index(['sport_id', 'slot_date']);

            $table->unique(
                ['venue_id', 'sport_id', 'slot_date', 'start_time', 'end_time'],
                'time_slot_availability_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_slots');
    }
};
