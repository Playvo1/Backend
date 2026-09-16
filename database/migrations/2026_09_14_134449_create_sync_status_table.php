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
        Schema::create('sync_status', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_token_id')
                ->constrained('device_tokens')
                ->cascadeOnDelete();

            $table->string('data_type');
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['device_token_id', 'data_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_status');
    }
};
