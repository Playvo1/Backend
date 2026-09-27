<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\BookingController;
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
 Route::get('/venues/{id}', [VenueController::class, 'show']);
  Route::get('/venues/{id}/time-slots', [VenueController::class, 'timeSlots']);
  Route::post('/bookings', [BookingController::class, 'store'])
    ->middleware('auth:sanctum');
    Route::post('/bookings/{id}/payment-receipt', [BookingController::class, 'uploadPaymentReceipt'])
    ->middleware('auth:sanctum');
});
