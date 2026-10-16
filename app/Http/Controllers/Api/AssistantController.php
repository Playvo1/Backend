<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AssistantQueryRequest;
use App\Models\TimeSlot;
use App\Services\Assistant\AssistantConversations;
use Illuminate\Http\JsonResponse;

/**
 * The player-facing booking assistant endpoint (US-4.5, API contract 7.8).
 * Players only, rate-limited per user to keep Gemini cost in check.
 *
 * The 7.8 fields keep their meaning; assistant v2 only adds optional ones (conversation_id,
 * intent, outcome, parsed_date_range, suggestions, options), so an app that reads only
 * reply_text and suggested_venue_id keeps working.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantConversations $conversations) {}

    public function query(AssistantQueryRequest $request): JsonResponse
    {
        // Only for a message without letters (a tap, "8") in a new conversation.
        $accept = strtolower((string) $request->header('Accept-Language'));
        $language = match (true) {
            str_starts_with($accept, 'ar') => 'ar',
            str_starts_with($accept, 'en') => 'en',
            default => null,
        };

        ['query' => $query, 'conversation' => $conversation, 'answer' => $answer] = $this->conversations->answer(
            $request->user(),
            $request->validated('query_text'),
            $request->validated('conversation_id'),
            [
                'option_id' => $request->validated('option_id'),
                'latitude' => $request->validated('latitude'),
                'longitude' => $request->validated('longitude'),
                'language' => $language,
            ],
        );

        $period = $answer['period'];

        return ApiResponse::send(true, 200, 'OK', [
            'assistant_query_id' => $query->id,
            'parsed_sport_id' => $query->parsed_sport_id,
            'parsed_date' => $query->parsed_date,
            'parsed_hour' => $query->parsed_hour === null ? null : substr($query->parsed_hour, 0, 5),
            'suggested_venue_id' => $query->suggested_venue_id,
            'reply_text' => $answer['reply_text'],
            'conversation_id' => $conversation->public_id,
            'intent' => $answer['intent'],
            'outcome' => $answer['outcome'],
            'parsed_date_range' => $period === null ? null : ['period' => $period['name'], 'from' => $period['from'], 'to' => $period['to']],
            'suggestions' => array_map(fn (array $option) => $this->suggestion($option['slot'], $option['distance_km'], $option['exact']), $answer['suggestions']),
            'options' => $answer['options'],
        ]);
    }

    /**
     * One option, with what a card needs and the slot to book.
     *
     * @return array<string, mixed>
     */
    private function suggestion(TimeSlot $slot, ?float $distanceKm, bool $exact): array
    {
        $venue = $slot->venue;

        return [
            'venue_id' => $venue->id,
            'time_slot_id' => $slot->id,
            'name_ar' => $venue->name_ar,
            'name_en' => $venue->name_en,
            'area_ar' => $venue->area_ar,
            'area_en' => $venue->area_en,
            'city_ar' => $venue->city?->name_ar,
            'city_en' => $venue->city?->name_en,
            'date' => substr((string) $slot->slot_date, 0, 10),
            'start_time' => substr((string) $slot->start_time, 0, 5),
            'end_time' => substr((string) $slot->end_time, 0, 5),
            'price' => (float) $slot->hourly_price,
            'rating' => (float) $venue->avg_rating,
            'distance_km' => $distanceKm,
            // False for an alternative close to the hour asked for, when none matched it.
            'exact' => $exact,
        ];
    }
}
