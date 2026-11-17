<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Lets a receipt notification link to its payment receipt. No foreign key, so a removed
    // receipt never blocks or deletes its notifications; existing rows stay null.
    public function up(): void
    {
        if (Schema::hasColumn('notifications', 'payment_receipt_id')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_receipt_id')->nullable()->after('booking_id');
            $table->index('payment_receipt_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('notifications', 'payment_receipt_id')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['payment_receipt_id']);
            $table->dropColumn('payment_receipt_id');
        });
    }
};
