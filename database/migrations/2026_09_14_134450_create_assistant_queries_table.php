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
        Schema::create('assistant_queries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->text('query_text');

            $table->foreignId('parsed_sport_id')
                ->nullable()
                ->constrained('sports')
                ->nullOnDelete();

            $table->date('parsed_date')->nullable();

            $table->time('parsed_hour')->nullable();

            $table->foreignId('suggested_venue_id')
                ->nullable()
                ->constrained('venues')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_queries');
    }
};
