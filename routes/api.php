<?php

use App\Http\Controllers\Api\AdminAccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\OwnerBookingController;
use App\Http\Controllers\Api\OwnerTimeSlotController;
use App\Http\Controllers\Api\OwnerVenueController;
use App\Http\Controllers\Api\VenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('auth/register/player', [AuthController::class, 'registerPlayer']);
    Route::post('auth/google', [AuthController::class, 'googleAuth']);
    Route::post('auth/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::get('venues', [VenueController::class, 'index']);
    Route::get('venues/{id}', [VenueController::class, 'show']);
    Route::get('venues/{id}/time-slots', [VenueController::class, 'timeSlots']);

    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware('auth:sanctum');
    Route::post('bookings/{id}/payment-receipt', [BookingController::class, 'uploadPaymentReceipt'])
        ->middleware('auth:sanctum');

    Route::prefix('owner')
        ->middleware(['auth:sanctum', 'role:venue_owner'])
        ->group(function () {
            Route::get('venues', [OwnerVenueController::class, 'index']);
            Route::put('venues/{venue}', [OwnerVenueController::class, 'update']);
            Route::post('venues/{venue}/images', [OwnerVenueController::class, 'uploadImage']);
            Route::post('venues/{venue}/time-slots', [OwnerTimeSlotController::class, 'store']);
            Route::put('time-slots/{timeSlot}', [OwnerTimeSlotController::class, 'update']);
            Route::delete('time-slots/{timeSlot}', [OwnerTimeSlotController::class, 'destroy']);
            Route::get('bookings', [OwnerBookingController::class, 'index']);
        });

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'role:admin'])
        ->group(function () {
            Route::post('venue-owners', [AdminAccountController::class, 'createVenueOwner']);
            Route::post('admins', [AdminAccountController::class, 'createAdmin']);
        });

});
