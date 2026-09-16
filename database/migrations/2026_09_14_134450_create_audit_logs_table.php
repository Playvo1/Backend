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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('action');

            $table->string('target_type');
            $table->unsignedBigInteger('target_id');

            $table->timestamps();

            $table->index(
                ['target_type', 'target_id'],
                'audit_logs_target_index'
            );

            $table->index('admin_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
