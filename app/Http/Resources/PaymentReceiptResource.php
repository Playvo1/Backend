<?php

namespace App\Http\Resources;

use App\Models\PaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A receipt in the admin review queue. receipt_url is a short-lived signed link, since
 * receipt images live in private storage and must never be publicly listable.
 *
 * @mixin PaymentReceipt
 */
class PaymentReceiptResource extends JsonResource
{
    public const URL_LIFETIME_MINUTES = 15;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'receipt_url' => URL::temporarySignedRoute(
                'admin.payment-receipts.image',
                now()->addMinutes(self::URL_LIFETIME_MINUTES),
                ['paymentReceipt' => $this->id],
            ),
            'status' => $this->status,
            'uploaded_at' => $this->uploaded_at?->toIso8601ZuluString(),
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601ZuluString(),
            'rejection_reason' => $this->rejection_reason,
            'booking' => (new BookingConfirmationResource($this->booking))->resolve(),
        ];
    }
}
