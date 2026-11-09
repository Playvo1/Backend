<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public reference lists used by the apps' forms and filters.
 */
class ReferenceDataController extends Controller
{
    public function countries(): JsonResponse
    {
        return ApiResponse::send(
            true,
            200,
            'Countries retrieved successfully.',
            Country::query()->orderBy('id')->get(['id', 'name_ar', 'name_en'])
        );
    }

    public function cities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_id' => ['sometimes', 'integer'],
        ]);

        $cities = City::query()
            ->with('country:id,name_ar,name_en')
            ->when(isset($validated['country_id']), fn ($q) => $q->where('country_id', $validated['country_id']))
            ->orderBy('id')
            ->get(['id', 'country_id', 'name_ar', 'name_en'])
            ->map(fn (City $city) => [
                'id' => $city->id,
                'name_ar' => $city->name_ar,
                'name_en' => $city->name_en,
                'country' => $city->country ? [
                    'id' => $city->country->id,
                    'name_ar' => $city->country->name_ar,
                    'name_en' => $city->country->name_en,
                ] : null,
            ])
            ->values();

        return ApiResponse::send(true, 200, 'Cities retrieved successfully.', $cities);
    }

    public function sports(): JsonResponse
    {
        return ApiResponse::send(
            true,
            200,
            'Sports retrieved successfully.',
            Sport::query()->orderBy('id')->get(['id', 'name_ar', 'name_en'])
        );
    }
}
