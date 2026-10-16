<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\AsksTheAssistant;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * POST /api/v1/assistant/feedback: the assistant's "rate your experience" screen (تقييم تجربتك).
 */
class AssistantFeedbackTest extends TestCase
{
    use AsksTheAssistant, BuildsPlayvoData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssistant();
    }

    private function rate(array $body)
    {
        return $this->postJson('/api/v1/assistant/feedback', $body);
    }

    public function test_a_player_rates_the_assistant_with_liked_aspects_and_a_comment(): void
    {
        $this->seedVenues();
        $this->ask('مرحبا');
        $conversation = AssistantConversation::where('public_id', $this->conversationId)->firstOrFail();

        $response = $this->rate([
            'rating' => 5,
            'liked_aspects' => ['booking_ease', 'info_accuracy'],
            'comment' => 'ممتاز وسريع',
            'conversation_id' => $this->conversationId,
        ])->assertCreated();

        $response->assertExactJson([
            'success' => true,
            'message' => 'Thank you for your feedback.',
            'errors' => null,
            'data' => [
                'feedback_id' => $response->json('data.feedback_id'),
                'rating' => 5,
                'liked_aspects' => [
                    ['key' => 'booking_ease', 'label_ar' => 'سهولة الحجز', 'label_en' => 'Ease of booking'],
                    ['key' => 'info_accuracy', 'label_ar' => 'دقة المعلومات', 'label_en' => 'Accurate information'],
                ],
                'comment' => 'ممتاز وسريع',
                'conversation_id' => $this->conversationId,
            ],
        ]);

        $feedback = AssistantFeedback::findOrFail($response->json('data.feedback_id'));
        $this->assertSame($this->player->id, $feedback->user_id);
        $this->assertSame($conversation->id, $feedback->assistant_conversation_id);
        $this->assertSame(['booking_ease', 'info_accuracy'], $feedback->liked_aspects);
    }

    public function test_only_the_rating_is_required(): void
    {
        $this->rate(['rating' => '3'])->assertCreated()
            ->assertJsonPath('data.rating', 3)
            ->assertJsonPath('data.liked_aspects', [])
            ->assertJsonPath('data.comment', null)
            ->assertJsonPath('data.conversation_id', null);
    }

    public function test_every_aspect_key_has_its_arabic_label(): void
    {
        $this->assertSame([
            'booking_ease' => 'سهولة الحجز',
            'venue_variety' => 'تنوع الملاعب',
            'info_accuracy' => 'دقة المعلومات',
            'app_experience' => 'تجربة التطبيق',
            'prices' => 'الأسعار',
            'customer_service' => 'خدمة العملاء',
        ], array_map(fn (array $labels) => $labels[0], AssistantFeedback::ASPECTS));

        $this->rate(['rating' => 4, 'liked_aspects' => array_keys(AssistantFeedback::ASPECTS)])->assertCreated();
    }

    public function test_the_form_is_validated(): void
    {
        // Refused requests count against the hourly limit too.
        config(['services.assistant.feedback_hourly_limit' => 100]);
        $invalid = [
            'no rating' => [[], 'rating'],
            'rating 0' => [['rating' => 0], 'rating'],
            'rating 6' => [['rating' => 6], 'rating'],
            'rating 4.5' => [['rating' => 4.5], 'rating'],
            'rating true' => [['rating' => true], 'rating'],
            'rating text' => [['rating' => 'five'], 'rating'],
            'unknown aspect' => [['rating' => 4, 'liked_aspects' => ['speed']], 'liked_aspects.0'],
            'repeated aspect' => [['rating' => 4, 'liked_aspects' => ['prices', 'prices']], 'liked_aspects.0'],
            'aspects not a list' => [['rating' => 4, 'liked_aspects' => 'prices'], 'liked_aspects'],
            'comment too long' => [['rating' => 4, 'comment' => str_repeat('ا', 501)], 'comment'],
            'comment not text' => [['rating' => 4, 'comment' => ['x']], 'comment'],
            'conversation not a uuid' => [['rating' => 4, 'conversation_id' => '12'], 'conversation_id'],
        ];

        foreach ($invalid as $case => [$body, $field]) {
            $this->rate($body)->assertUnprocessable()->assertJson(['success' => false, 'data' => null])->assertJsonValidationErrors([$field]);
        }

        $this->post('/api/v1/assistant/feedback', ['rating' => 4, 'comment' => "bad \xB1\x31"], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->rate(['rating' => 4, 'comment' => str_repeat('ا', 500)])->assertCreated();
        $this->assertSame(1, AssistantFeedback::count());
    }

    public function test_the_conversation_must_be_the_players_own(): void
    {
        $this->seedVenues();
        $this->ask('مرحبا');
        $theirs = $this->conversationId;

        $this->actingAsRole('player');
        $this->rate(['rating' => 1, 'conversation_id' => $theirs])
            ->assertForbidden()
            ->assertJsonPath('message', 'This conversation belongs to another player.');
        $this->rate(['rating' => 1, 'conversation_id' => (string) Str::uuid()])->assertNotFound();

        $this->assertSame(0, AssistantFeedback::count());
    }

    public function test_only_signed_in_players_can_rate(): void
    {
        foreach (['venue_owner', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->rate(['rating' => 5])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->rate(['rating' => 5])->assertUnauthorized();
        $this->assertSame(0, AssistantFeedback::count());
    }

    public function test_ratings_are_rate_limited_per_player(): void
    {
        foreach (range(1, 10) as $i) {
            $this->rate(['rating' => 4])->assertCreated();
        }

        $this->rate(['rating' => 4])->assertStatus(429)->assertExactJson([
            'success' => false,
            'data' => null,
            'message' => "You've reached the limit of 10 ratings per hour. Please try again later.",
            'errors' => null,
        ]);

        // Questions to the assistant are counted apart.
        $this->ask('مرحبا')->assertOk();
    }
}
