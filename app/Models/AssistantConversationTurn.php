<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of a conversation and the assistant's reply; the question itself is also in
 * ASSISTANT_QUERY, which the turn links to.
 */
class AssistantConversationTurn extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'assistant_conversation_id',
        'assistant_query_id',
        'query_text',
        'option_id',
        'reply_text',
        'intent',
        'outcome',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }

    public function assistantQuery(): BelongsTo
    {
        return $this->belongsTo(AssistantQuery::class, 'assistant_query_id');
    }
}
