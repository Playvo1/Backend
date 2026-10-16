<?php

namespace App\Services\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantConversationTurn;
use App\Models\AssistantQuery;
use App\Models\User;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runs one turn of a player's conversation with the booking assistant: finds (or starts) the
 * conversation, answers with BookingAssistant from what earlier turns understood, logs the
 * question to ASSISTANT_QUERY (US-4.5) and keeps the turn and the new state.
 */
class AssistantConversations
{
    public function __construct(private readonly BookingAssistant $assistant) {}

    /**
     * The player's own conversation by its public id: 404 when there is none, 403 when it
     * belongs to someone else.
     */
    public function find(User $user, string $publicId): AssistantConversation
    {
        $conversation = AssistantConversation::query()->where('public_id', $publicId)->first();

        if ($conversation === null) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        if ($conversation->user_id !== $user->id) {
            throw new AccessDeniedHttpException('This conversation belongs to another player.');
        }

        return $conversation;
    }

    /**
     * @param  array{option_id?: ?string, latitude?: mixed, longitude?: mixed, language?: ?string}  $input
     * @return array{query: AssistantQuery, conversation: AssistantConversation, answer: array<string, mixed>}
     */
    public function answer(User $user, ?string $queryText, ?string $conversationId, array $input = []): array
    {
        $conversation = $conversationId === null ? null : $this->find($user, $conversationId);

        // An idle conversation starts afresh; the shared location it kept is forgotten.
        if ($conversation !== null && $conversation->isExpired()) {
            $conversation->update(['state' => ConversationState::fromArray($conversation->state)->with(['latitude' => null, 'longitude' => null])->toArray()]);
            $conversation = null;
        }

        $state = $conversation?->conversationState() ?? ConversationState::empty();
        $history = $conversation?->turns()->latest('id')->limit(3)->get(['query_text', 'reply_text'])->reverse()
            ->map(fn (AssistantConversationTurn $turn) => ['query' => $turn->query_text, 'reply' => $turn->reply_text])
            ->values()
            ->all() ?? [];

        // "Today" and "already started" are the players' local time, not the server's UTC.
        $answer = $this->assistant->respond($queryText ?? '', LocalClock::now(), state: $state, context: [...$input, 'history' => $history]);
        $arguments = $answer['arguments'];

        return DB::transaction(function () use ($user, $conversation, $answer, $arguments, $input) {
            $conversation ??= AssistantConversation::create(['public_id' => (string) Str::uuid(), 'user_id' => $user->id]);

            $query = AssistantQuery::create([
                'user_id' => $user->id,
                'query_text' => $answer['query_text'],
                'parsed_sport_id' => $answer['sport']?->id,
                'parsed_date' => $arguments['date'] ?? null,
                'parsed_hour' => isset($arguments['hour']) ? $arguments['hour'].':00' : null,
                'suggested_venue_id' => $answer['suggested_venue_id'],
            ]);

            $conversation->turns()->create([
                'assistant_query_id' => $query->id,
                'query_text' => $answer['query_text'],
                'option_id' => $input['option_id'] ?? null,
                'reply_text' => $answer['reply_text'],
                'intent' => $answer['intent'],
                'outcome' => $answer['outcome'],
            ]);

            $conversation->update(['state' => $answer['state']->toArray(), 'last_activity_at' => now()]);

            return ['query' => $query, 'conversation' => $conversation, 'answer' => $answer];
        });
    }
}
