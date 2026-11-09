<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    public function test_countries_are_public_and_sorted_by_id(): void
    {
        $palestine = Country::forceCreate(['name_ar' => 'فلسطين', 'name_en' => 'Palestine']);
        $egypt = Country::forceCreate(['name_ar' => 'مصر', 'name_en' => 'Egypt']);

        $this->getJson('/api/v1/countries')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data', [
                ['id' => $palestine->id, 'name_ar' => 'فلسطين', 'name_en' => 'Palestine'],
                ['id' => $egypt->id, 'name_ar' => 'مصر', 'name_en' => 'Egypt'],
            ]);
    }

    public function test_cities_include_their_country_and_filter_by_country(): void
    {
        $gaza = $this->makeCity('Gaza', 'غزة');
        $khanYounis = $this->makeCity('Khan Younis', 'خان يونس');
        $egypt = Country::forceCreate(['name_ar' => 'مصر', 'name_en' => 'Egypt']);
        $cairo = City::forceCreate(['country_id' => $egypt->id, 'name_ar' => 'القاهرة', 'name_en' => 'Cairo']);

        $this->getJson('/api/v1/cities')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0', [
                'id' => $gaza->id,
                'name_ar' => 'غزة',
                'name_en' => 'Gaza',
                'country' => ['id' => $gaza->country_id, 'name_ar' => 'فلسطين', 'name_en' => 'Palestine'],
            ])
            ->assertJsonPath('data.1.id', $khanYounis->id)
            ->assertJsonPath('data.2.id', $cairo->id);

        $this->getJson("/api/v1/cities?country_id={$egypt->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_en', 'Cairo');

        $this->getJson('/api/v1/cities?country_id=abc')->assertStatus(422);
    }

    public function test_sports_are_public_and_sorted_by_id(): void
    {
        $football = $this->makeSport();
        $basketball = $this->makeSport('Basketball', 'كرة السلة');

        $this->getJson('/api/v1/sports')
            ->assertOk()
            ->assertJsonPath('data', [
                ['id' => $football->id, 'name_ar' => 'كرة القدم', 'name_en' => 'Football'],
                ['id' => $basketball->id, 'name_ar' => 'كرة السلة', 'name_en' => 'Basketball'],
            ]);
    }
}
