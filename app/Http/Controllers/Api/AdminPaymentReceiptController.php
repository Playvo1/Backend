<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminReceiptIndexRequest;
use App\Http\Requests\Api\ReviewPaymentReceiptRequest;
use App\Http\Resources\BookingConfirmationResource;
use App\Http\Resources\PaymentReceiptResource;
use App\Models\PaymentReceipt;
use App\Services\Payments\PaymentReceiptReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin payment receipt review (US-3.6): the oldest-first queue, the receipt image
 * behind a signed URL, and the verify/reject decision.
 */
class AdminPaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceiptReviewService $reviews) {}

    public function index(AdminReceiptIndexRequest $request): JsonResponse
    {
        $receipts = PaymentReceipt::query()
            ->where('status', $request->query('status', 'pending'))
            ->with('booking.timeSlot.venue')
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        $items = PaymentReceiptResource::collection($receipts->items())->resolve();

        return ApiResponse::send(true, 200, 'OK', ApiResponse::paginated($receipts, $items));
    }

    /**
     * Reached through the signed URL in receipt_url, so an <img> tag can load it without a bearer token.
     */
    public function image(PaymentReceipt $paymentReceipt): StreamedResponse
    {
        [$disk, $path] = $paymentReceipt->imageLocation();

        if (! Storage::disk($disk)->exists($path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk($disk)->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function review(ReviewPaymentReceiptRequest $request, PaymentReceipt $paymentReceipt): JsonResponse
    {
        if ($request->validated('status') === 'rejected') {
            $receipt = $this->reviews->reject($paymentReceipt, $request->user(), $request->validated('rejection_reason'));

            return ApiResponse::send(true, 200, 'Receipt rejected, player notified', [
                'id' => $receipt->id,
                'status' => $receipt->status,
                'rejection_reason' => $receipt->rejection_reason,
            ]);
        }

        $receipt = $this->reviews->verify($paymentReceipt, $request->user());

        return ApiResponse::send(true, 200, 'Receipt verified, booking confirmed', [
            'id' => $receipt->id,
            'status' => $receipt->status,
            'verified_by' => $receipt->verified_by,
            'verified_at' => $receipt->verified_at->toIso8601ZuluString(),
            'booking' => (new BookingConfirmationResource($receipt->booking))->resolve(),
        ]);
    }
}
