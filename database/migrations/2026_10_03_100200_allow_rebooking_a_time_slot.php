<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expired bookings are now cancelled rather than deleted (contract 7.6), so one slot can
 * have a cancelled booking and later a new one. The unique index on time_slot_id becomes
 * a plain index; BookingController still allows at most one active booking per slot.
 * The plain index is added first because MySQL needs an index behind the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('time_slot_id', 'bookings_time_slot_id_index');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_time_slot_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unique('time_slot_id', 'bookings_time_slot_id_unique');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_time_slot_id_index');
        });
    }
};
