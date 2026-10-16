<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "rate your experience" screen of the assistant (assistant v2): a 1-5 rating, the
 * aspects the player liked and an optional comment, optionally about one conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('assistant_conversation_id')
                ->nullable()
                ->constrained('assistant_conversations')
                ->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            // Keys from AssistantFeedback::ASPECTS.
            $table->json('liked_aspects')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_feedback');
    }
};
