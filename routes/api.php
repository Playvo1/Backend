<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\TimeSlotController;
use App\Http\Controllers\Api\OwnerBookingController;
use App\Http\Controllers\Api\AdminVenueController;
use App\Http\Controllers\Api\AdminPaymentReceiptController;
use App\Http\Controllers\Api\VenueRatingController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\NotificationController;
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


   Route::middleware('auth:sanctum')->group(function () {

    Route::get('/bookings', [BookingController::class, 'history']);

    Route::post('/bookings', [BookingController::class, 'store']);

    Route::post('/bookings/{id}/payment-receipt', [BookingController::class, 'uploadPaymentReceipt']);

    Route::post('/bookings/{id}/rating', [VenueRatingController::class, 'store']);
Route::post('/venues/{id}/favorite', [FavoriteController::class, 'toggle']);

    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
Route::get('/favorites', [FavoriteController::class, 'index']);
});


Route::get('/bookings/share/{share_token}',[BookingController::class, 'share']);


    Route::prefix('venue_owner')
        ->middleware(['auth:sanctum', 'role:venue_owner'])
        ->group(function () {

         Route::put('owner/venues/{id}', [VenueController::class, 'update']);

    Route::post('owner/venues/{id}/images', [VenueController::class, 'uploadImage']);

   Route::post('owner/venues/{id}/time-slots', [TimeSlotController::class, 'store']);


  Route::put('owner/time-slots/{id}', [TimeSlotController::class, 'update']);


    Route::get('owner/bookings', [OwnerBookingController::class, 'index']);

});
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'role:admin'])
        ->group(function () {
            Route::post('admin/venues', [AdminVenueController::class, 'store']);

            Route::put('admin/venues/{id}/status', [AdminVenueController::class, 'updateStatus']);
            Route::post('admin/venues', [AdminVenueController::class, 'store']);

            Route::put('admin/venues/{id}/status', [AdminVenueController::class, 'updateStatus']);

            Route::get('payment-receipts', [AdminPaymentReceiptController::class, 'index']);
            Route::put('payment-receipts/{id}/verify', [AdminPaymentReceiptController::class, 'verify']);
            Route::put('payment-receipts/{id}/reject', [AdminPaymentReceiptController::class, 'reject']);
        });

});
