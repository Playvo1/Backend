<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remove foreign key first because MySQL may be using
        // the unique index to support the foreign key.
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['time_slot_id']);
        });

        // Drop the unique index.
        $indexes = DB::select("SHOW INDEX FROM bookings");

        $uniqueExists = collect($indexes)->contains(function ($index) {
            return $index->Key_name === 'bookings_time_slot_id_unique';
        });

        if ($uniqueExists) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique('bookings_time_slot_id_unique');
            });
        }

        // Add a normal index for the foreign key.
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('time_slot_id', 'bookings_time_slot_id_index');
        });

        // Restore the foreign key.
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreign('time_slot_id')
                ->references('id')
                ->on('time_slots');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['time_slot_id']);
            $table->dropIndex('bookings_time_slot_id_index');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unique('time_slot_id');

            $table->foreign('time_slot_id')
                ->references('id')
                ->on('time_slots');
        });
    }
};
