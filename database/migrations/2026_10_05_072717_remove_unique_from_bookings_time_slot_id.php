<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = DB::select("SHOW INDEX FROM bookings");

        $uniqueExists = collect($indexes)->contains(function ($index) {
            return $index->Key_name === 'bookings_time_slot_id_unique';
        });

        if ($uniqueExists) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique('bookings_time_slot_id_unique');
            });
        }
    }

    public function down(): void
    {
        $indexes = DB::select("SHOW INDEX FROM bookings");

        $uniqueExists = collect($indexes)->contains(function ($index) {
            return $index->Key_name === 'bookings_time_slot_id_unique';
        });

        if (!$uniqueExists) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unique('time_slot_id');
            });
        }
    }
};
