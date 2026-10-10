
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        $indexes = Schema::getIndexes('bookings');

        foreach ($indexes as $index) {
            if (
                ($index['name'] ?? null) === 'bookings_time_slot_id_unique'
                && ($index['unique'] ?? false)
            ) {
                Schema::table('bookings', function (Blueprint $table) {
                    $table->dropUnique('bookings_time_slot_id_unique');
                });

                break;
            }
        }
    }

    public function down(): void
    {
    }
};
