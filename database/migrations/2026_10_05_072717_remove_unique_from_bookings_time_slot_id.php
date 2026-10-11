
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        // Check whether the unique index exists.
        $indexes = Schema::getIndexes('bookings');

        $hasUniqueIndex = false;

        foreach ($indexes as $index) {
            if (
                ($index['name'] ?? null) === 'bookings_time_slot_id_unique'
                && ($index['unique'] ?? false)
            ) {
                $hasUniqueIndex = true;
                break;
            }
        }

        if (! $hasUniqueIndex) {
            return;
        }

        // Remove the foreign key before dropping the unique index.
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['time_slot_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_time_slot_id_unique');
        });

        // Add a normal index for the foreign key.
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(
                'time_slot_id',
                'bookings_time_slot_id_index'
            );
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
        if (! Schema::hasTable('bookings')) {
            return;
        }

        // Restore the original unique constraint if it is absent.
        $indexes = Schema::getIndexes('bookings');

        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === 'bookings_time_slot_id_unique') {
                return;
            }
        }

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
