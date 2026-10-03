<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AssistantQueryRequest;
use App\Services\Assistant\BookingAssistant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * The player-facing booking assistant endpoint (US-4.5, API contract 7.8).
 * Players only, rate-limited per user to keep Gemini cost in check.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly BookingAssistant $assistant) {}

    public function query(AssistantQueryRequest $request): JsonResponse
    {
        ['query' => $query, 'reply_text' => $replyText] = $this->assistant->answer(
            $request->user(),
            $request->validated('query_text'),
        );

        return ApiResponse::send(true, 200, 'OK', [
            'assistant_query_id' => $query->id,
            'parsed_sport_id' => $query->parsed_sport_id,
            'parsed_date' => $query->parsed_date,
            'parsed_hour' => $query->parsed_hour ? Carbon::parse($query->parsed_hour)->format('H:i') : null,
            'suggested_venue_id' => $query->suggested_venue_id,
            'reply_text' => $replyText,
        ]);
    }
}
