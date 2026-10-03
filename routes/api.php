<?php

use App\Http\Controllers\Api\AdminAccountController;
use App\Http\Controllers\Api\AdminPaymentReceiptController;
use App\Http\Controllers\Api\AdminVenueController;
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

            Route::get('venues', [AdminVenueController::class, 'index']);
            Route::post('venues', [AdminVenueController::class, 'store']);
            Route::put('venues/{venue}/status', [AdminVenueController::class, 'updateStatus'])->withTrashed();

            Route::get('payment-receipts', [AdminPaymentReceiptController::class, 'index']);
            Route::put('payment-receipts/{paymentReceipt}/verify', [AdminPaymentReceiptController::class, 'review']);
        });

    // Receipt images are private; admins load them through the short-lived signed
    // receipt_url from the review queue, so no bearer token is needed here.
    Route::get('admin/payment-receipts/{paymentReceipt}/image', [AdminPaymentReceiptController::class, 'image'])
        ->middleware('signed')
        ->name('admin.payment-receipts.image');

});
