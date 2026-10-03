<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENT_RECEIPT.rejection_reason (ERD): the admin's free-text reason, shown to the
 * player so they can correct and re-upload the receipt (US-3.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->string('rejection_reason', 500)->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
