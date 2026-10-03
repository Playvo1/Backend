<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReviewAuthorizationTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $ownerA;

    private User $ownerB;

    private Venue $venueA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedRoles();
        $sport = $this->makeSport();
        $this->ownerA = $this->userWithRole('venue_owner');
        $this->ownerB = $this->userWithRole('venue_owner');
        $this->venueA = $this->makeVenue($this->ownerA);
        $this->venueA->sports()->sync([$sport->id]);
    }

    private function assertUnifiedError($response, int $status): void
    {
        $response->assertStatus($status)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonStructure(['success', 'data', 'message', 'errors']);
    }

    public function test_owner_cannot_edit_another_owners_venue(): void
    {
        Sanctum::actingAs($this->ownerB);

        $this->assertUnifiedError($this->putJson("/api/v1/owner/venues/{$this->venueA->id}", ['name_en' => 'Hijacked']), 403);
        $this->assertSame('Green Field Court', $this->venueA->fresh()->name_en);
    }

    public function test_owner_cannot_upload_to_another_owners_venue(): void
    {
        Sanctum::actingAs($this->ownerB);
        $file = UploadedFile::fake()->createWithContent('p.png', base64_decode(self::PNG));

        $this->assertUnifiedError($this->post("/api/v1/owner/venues/{$this->venueA->id}/images", ['file' => $file], ['Accept' => 'application/json']), 403);
        $this->assertSame(0, $this->venueA->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_owner_cannot_create_update_or_delete_another_owners_slots(): void
    {
        $slot = $this->makeSlot($this->venueA);
        Sanctum::actingAs($this->ownerB);

        $this->assertUnifiedError($this->postJson("/api/v1/owner/venues/{$this->venueA->id}/time-slots", [
            'sport_id' => $this->venueA->sports()->value('sports.id'), 'slot_date' => now()->addDays(5)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'hourly_price' => 10,
        ]), 403);
        $this->assertUnifiedError($this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 1]), 403);
        $this->assertUnifiedError($this->deleteJson("/api/v1/owner/time-slots/{$slot->id}"), 403);
        $this->assertEquals(40, $slot->fresh()->hourly_price);
    }

    public function test_owner_cannot_read_bookings_of_another_owners_venue(): void
    {
        $this->makeBooking($this->makeSlot($this->venueA));
        Sanctum::actingAs($this->ownerB);

        $this->assertUnifiedError($this->getJson("/api/v1/owner/bookings?venue_id={$this->venueA->id}"), 403);
        $this->getJson('/api/v1/owner/bookings')->assertOk()->assertJsonPath('data.total', 0);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'dashboard' => ['GET', '/api/v1/admin/dashboard-stats'],
            'venues index' => ['GET', '/api/v1/admin/venues'],
            'venues store' => ['POST', '/api/v1/admin/venues'],
            'venue status' => ['PUT', '/api/v1/admin/venues/{venue}/status'],
            'receipts index' => ['GET', '/api/v1/admin/payment-receipts'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_player_and_owner_get_403_on_admin_routes(string $method, string $uri): void
    {
        $uri = str_replace('{venue}', (string) $this->venueA->id, $uri);
        foreach (['player', 'venue_owner'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->assertUnifiedError($this->json($method, $uri, ['status' => 'active']), 403);
        }
    }

    #[DataProvider('adminRoutes')]
    public function test_unauthenticated_admin_routes_return_401_json(string $method, string $uri): void
    {
        $this->assertUnifiedError($this->json($method, $uri), 401);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ownerRoutes(): array
    {
        return [
            'venues index' => ['GET', '/api/v1/owner/venues'],
            'venue update' => ['PUT', '/api/v1/owner/venues/{venue}'],
            'venue image' => ['POST', '/api/v1/owner/venues/{venue}/images'],
            'slot create' => ['POST', '/api/v1/owner/venues/{venue}/time-slots'],
            'slot update' => ['PUT', '/api/v1/owner/time-slots/{slot}'],
            'slot delete' => ['DELETE', '/api/v1/owner/time-slots/{slot}'],
            'bookings' => ['GET', '/api/v1/owner/bookings'],
        ];
    }

    #[DataProvider('ownerRoutes')]
    public function test_admin_and_player_get_403_on_owner_routes(string $method, string $uri): void
    {
        $slot = $this->makeSlot($this->venueA);
        $uri = str_replace(['{venue}', '{slot}'], [$this->venueA->id, $slot->id], $uri);

        foreach (['admin', 'player'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->assertUnifiedError($this->json($method, $uri, ['name_en' => 'x', 'hourly_price' => 1]), 403);
        }

        $this->assertSame('Green Field Court', $this->venueA->fresh()->name_en);
    }

    #[DataProvider('ownerRoutes')]
    public function test_unauthenticated_owner_routes_return_401_json(string $method, string $uri): void
    {
        $this->assertUnifiedError($this->json($method, $uri), 401);
    }

    public function test_non_admin_probing_a_missing_receipt_gets_403_not_404(): void
    {
        // Route model binding runs before the role middleware, so a player can tell
        // which receipt ids exist (403) from the ones that don't (404).
        Sanctum::actingAs($this->userWithRole('player'));

        $this->assertUnifiedError($this->putJson('/api/v1/admin/payment-receipts/999/verify', ['status' => 'verified']), 403);
    }

    public function test_assistant_requires_auth_and_player_role(): void
    {
        $this->assertUnifiedError($this->postJson('/api/v1/assistant/query', ['query_text' => 'football']), 401);

        foreach (['venue_owner', 'admin'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->assertUnifiedError($this->postJson('/api/v1/assistant/query', ['query_text' => 'football']), 403);
        }
    }

    public function test_invalid_bearer_token_returns_401_json(): void
    {
        $this->assertUnifiedError(
            $this->getJson('/api/v1/owner/venues', ['Authorization' => 'Bearer 999|nonsense']),
            401,
        );
    }
}
