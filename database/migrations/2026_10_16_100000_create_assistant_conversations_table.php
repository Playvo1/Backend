<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking assistant v2: a player's conversation with the assistant, so a follow-up is read
 * against the earlier turns. ASSISTANT_QUERY still logs every question (US-4.5); a turn
 * links the question to its conversation and keeps the reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            // The id the app sends back; not the row id, so conversations can't be enumerated.
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // What the conversation has understood so far (ConversationState).
            $table->json('state')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('assistant_conversation_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_conversation_id')
                ->constrained('assistant_conversations')
                ->cascadeOnDelete();
            $table->foreignId('assistant_query_id')
                ->nullable()
                ->constrained('assistant_queries')
                ->nullOnDelete();
            $table->text('query_text');
            $table->string('option_id', 40)->nullable();
            $table->text('reply_text');
            $table->string('intent', 30);
            $table->string('outcome', 30);
            $table->timestamps();

            $table->index('assistant_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversation_turns');
        Schema::dropIfExists('assistant_conversations');
    }
};
