<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Venue;
use App\Services\Assistant\VenueSearchTool;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the assistant v2 feature tests: a small Gaza fixture with coordinates, and
 * requests that carry the conversation on like the app does. Uses BuildsPlayvoData.
 *
 * Saturday 2026-10-03 12:00 in Gaza (09:00 UTC): "today" is Saturday, "tomorrow" Sunday.
 */
trait AsksTheAssistant
{
    protected User $player;

    protected ?string $conversationId = null;

    /**
     * @var array<string, Venue>
     */
    protected array $venues = [];

    protected function setUpAssistant(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-10-03 09:00:00');
        config([
            'app.local_timezone' => 'Asia/Gaza',
            'services.gemini.key' => null,
            'services.gemini.model' => 'gemini-test',
        ]);

        $this->seedRoles();
        $this->player = $this->actingAsRole('player');
    }

    /**
     * Four Football venues: two in Gaza with coordinates, one in Khan Younis, one without
     * coordinates. Ratings and prices differ so every ranking is visible.
     */
    protected function seedVenues(): void
    {
        $gaza = $this->makeCity('Gaza', 'غزة');
        $khanYounis = $this->makeCity('Khan Younis', 'خانيونس');
        $this->makeSport();

        $this->venues = [
            'green' => $this->makeVenue(null, ['city_id' => $gaza->id, 'name_ar' => 'ملعب الحقل الأخضر', 'name_en' => 'Green Field Court', 'area_ar' => 'الرمال', 'area_en' => 'Al-Rimal', 'avg_rating' => 4.5, 'latitude' => 31.5200, 'longitude' => 34.4500]),
            'jalaa' => $this->makeVenue(null, ['city_id' => $gaza->id, 'name_ar' => 'ملعب الجلاء', 'name_en' => 'Al-Jalaa Stadium', 'area_ar' => 'الجلاء', 'area_en' => 'Al-Jalaa', 'avg_rating' => 4.0, 'latitude' => 31.5100, 'longitude' => 34.4620]),
            'south' => $this->makeVenue(null, ['city_id' => $khanYounis->id, 'name_ar' => 'الملعب الجنوبي', 'name_en' => 'South Court', 'area_ar' => 'بني سهيلا', 'area_en' => 'Bani Suheila', 'avg_rating' => 4.8, 'latitude' => 31.3400, 'longitude' => 34.3000]),
            'beach' => $this->makeVenue(null, ['city_id' => $gaza->id, 'name_ar' => 'ملعب الشاطئ', 'name_en' => 'Beach Court', 'area_ar' => 'الشاطئ', 'area_en' => 'Al-Shati', 'avg_rating' => 3.0]),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra  conversation_id, option_id, latitude, longitude...
     */
    protected function ask(?string $text, array $extra = []): TestResponse
    {
        $body = array_filter([
            'query_text' => $text,
            'conversation_id' => $this->conversationId,
            ...$extra,
        ], fn (mixed $value) => $value !== null);

        $response = $this->postJson('/api/v1/assistant/query', $body);

        if ($response->isOk()) {
            $this->conversationId = $response->json('data.conversation_id');
        }

        return $response;
    }

    protected function choose(string $optionId, array $extra = []): TestResponse
    {
        return $this->ask(null, ['option_id' => $optionId, ...$extra]);
    }

    protected function newConversation(): void
    {
        $this->conversationId = null;
    }

    /**
     * @param  array<string, string>|callable  $args  the call's args, or a closure answering each request
     */
    protected function fakeGemini(array|callable $args): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => is_callable($args)
            ? $args
            : Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => VenueSearchTool::NAME, 'args' => $args]],
            ]]]]])]);
    }

    /**
     * The venue names of the suggestions, in order.
     *
     * @return list<string>
     */
    protected function suggested(TestResponse $response): array
    {
        return array_column($response->json('data.suggestions') ?? [], 'name_en');
    }

    protected function optionIds(TestResponse $response): array
    {
        return array_column($response->json('data.options') ?? [], 'id');
    }
}
