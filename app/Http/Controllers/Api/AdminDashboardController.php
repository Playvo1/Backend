<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardStatistics;
use Illuminate\Http\JsonResponse;

/**
 * The admin dashboard home screen's summary cards (US-5.1, API contract 7.9).
 */
class AdminDashboardController extends Controller
{
    public function stats(DashboardStatistics $statistics): JsonResponse
    {
        return ApiResponse::send(true, 200, 'OK', $statistics->summary());
    }
}
