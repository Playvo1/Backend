<?php

namespace App\Models;

use App\Services\Assistant\ConversationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A player's conversation with the booking assistant (assistant v2). The app refers to it by
 * public_id (the API's conversation_id); state is what the turns so far have understood.
 */
class AssistantConversation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'user_id',
        'state',
        'last_activity_at',
    ];

    protected $casts = [
        'state' => 'array',
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(AssistantConversationTurn::class, 'assistant_conversation_id');
    }

    public function conversationState(): ConversationState
    {
        return ConversationState::fromArray($this->state);
    }

    /**
     * Idle for longer than the configured minutes: the next message starts afresh.
     */
    public function isExpired(): bool
    {
        $minutes = config('services.assistant.conversation_minutes');

        return $this->last_activity_at === null || $this->last_activity_at->lt(now()->subMinutes($minutes));
    }
}
