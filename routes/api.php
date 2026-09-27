<?php

use App\Http\Controllers\Api\AdminAccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\VenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register/player', [AuthController::class, 'registerPlayer']);
        Route::post('auth/google', [AuthController::class, 'googleAuth']);
        Route::post('auth/send-otp', [AuthController::class, 'sendOtp']);
        Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('auth/login', [AuthController::class, 'login']);

        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::get('venues', [VenueController::class, 'index']);
    Route::get('venues/{id}', [VenueController::class, 'show']);
    Route::get('venues/{id}/time-slots', [VenueController::class, 'timeSlots']);

    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware('auth:sanctum');
    Route::post('bookings/{id}/payment-receipt', [BookingController::class, 'uploadPaymentReceipt'])
        ->middleware('auth:sanctum');

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'role:admin'])
        ->group(function () {
            Route::post('venue-owners', [AdminAccountController::class, 'createVenueOwner']);
            Route::post('admins', [AdminAccountController::class, 'createAdmin']);
        });

});
