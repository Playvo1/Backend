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
        Schema::create('venues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('owner_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('city_id')
                ->constrained('cities')
                ->restrictOnDelete();

            $table->string('name_ar');
            $table->string('name_en');

            $table->text('address_ar')->nullable();
            $table->text('address_en')->nullable();

            $table->string('area_ar')->nullable();
            $table->string('area_en')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('length_m', 8, 2)->nullable();
            $table->decimal('width_m', 8, 2)->nullable();

            $table->decimal('avg_rating', 3, 2)->default(0);
            $table->decimal('min_hourly_price', 10, 2)->nullable();

            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index('owner_id');
            $table->index('city_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
