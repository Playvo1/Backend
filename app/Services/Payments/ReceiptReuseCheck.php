<?php

namespace App\Services\Payments;

use App\Models\PaymentReceipt;

/**
 * US-3.6: before a receipt is verified, re-check that the same transfer image (same
 * receipt_hash) hasn't already confirmed a different booking. receipt_hash is unique, so
 * uploads already reject a reused image; this keeps verification safe even for data
 * that predates or bypasses that constraint.
 */
class ReceiptReuseCheck
{
    public function usedForAnotherBooking(PaymentReceipt $receipt): bool
    {
        return PaymentReceipt::query()
            ->where('receipt_hash', $receipt->receipt_hash)
            ->where('booking_id', '!=', $receipt->booking_id)
            ->where('status', 'verified')
            ->exists();
    }
}
