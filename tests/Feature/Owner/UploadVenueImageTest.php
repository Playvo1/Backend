<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class UploadVenueImageTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    // A valid 1x1 PNG, so the tests don't depend on the GD extension.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake('public');
    }

    private function photo(string $name = 'court.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    public function test_owner_uploads_a_photo_with_a_sort_order(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $response = $this->postJson("/api/v1/owner/venues/{$venue->id}/images", [
            'file' => $this->photo(),
            'sort_order' => 1,
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true, 'message' => 'Image uploaded', 'errors' => null])
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonStructure(['data' => ['id', 'image_url', 'sort_order']]);

        $this->assertDatabaseHas('images', [
            'imageable_type' => 'VENUE',
            'imageable_id' => $venue->id,
            'sort_order' => 1,
        ]);
        $this->assertCount(1, Storage::disk('public')->files("venues/{$venue->id}"));
    }

    public function test_photo_without_sort_order_goes_to_the_end_of_the_gallery(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $this->postJson("/api/v1/owner/venues/{$venue->id}/images", ['file' => $this->photo(), 'sort_order' => 4]);

        $this->postJson("/api/v1/owner/venues/{$venue->id}/images", ['file' => $this->photo()])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 5);
    }

    public function test_public_venue_page_lists_photos_in_dashboard_order(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $second = $this->postJson("/api/v1/owner/venues/{$venue->id}/images", ['file' => $this->photo('b.png'), 'sort_order' => 2])->json('data.id');
        $first = $this->postJson("/api/v1/owner/venues/{$venue->id}/images", ['file' => $this->photo('a.png'), 'sort_order' => 1])->json('data.id');

        $this->getJson("/api/v1/venues/{$venue->id}")
            ->assertOk()
            ->assertJsonPath('data.images.0.id', $first)
            ->assertJsonPath('data.images.1.id', $second);
    }

    public function test_unsupported_file_types_are_rejected_with_a_clear_error(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $this->postJson("/api/v1/owner/venues/{$venue->id}/images", [
            'file' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file'])
            ->assertJsonPath('errors.file.0', 'Unsupported file type. Upload a JPG, PNG or WEBP image.');

        $this->assertDatabaseCount('images', 0);
    }

    public function test_file_is_required_and_sort_order_must_be_a_number(): void
    {
        $owner = $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue($owner);

        $this->postJson("/api/v1/owner/venues/{$venue->id}/images", ['sort_order' => 'first'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'sort_order']);
    }

    public function test_owner_cannot_add_photos_to_another_owners_venue(): void
    {
        $this->actingAsRole('venue_owner');
        $venue = $this->makeVenue();

        $this->postJson("/api/v1/owner/venues/{$venue->id}/images", ['file' => $this->photo()])
            ->assertForbidden()
            ->assertJson(['success' => false]);

        $this->assertDatabaseCount('images', 0);
    }

    public function test_unknown_venue_returns_404(): void
    {
        $this->actingAsRole('venue_owner');

        $this->postJson('/api/v1/owner/venues/999/images', ['file' => $this->photo()])->assertNotFound();
    }

    public function test_players_cannot_upload_venue_photos(): void
    {
        $venue = $this->makeVenue();
        $this->actingAsRole('player');

        $this->postJson("/api/v1/owner/venues/{$venue->id}/images", ['file' => $this->photo()])->assertForbidden();
    }
}
