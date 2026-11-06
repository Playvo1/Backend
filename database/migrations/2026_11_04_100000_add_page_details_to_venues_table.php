<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TEXT_COLUMNS = ['description_ar', 'description_en'];

    private const STRING_COLUMNS = [
        'surface_ar', 'surface_en',
        'team_format_ar', 'team_format_en',
        'capacity_ar', 'capacity_en',
    ];

    private const PRICE_COLUMNS = ['morning_hourly_price', 'evening_hourly_price'];

    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            foreach (self::TEXT_COLUMNS as $column) {
                if (!Schema::hasColumn('venues', $column)) {
                    $table->text($column)->nullable();
                }
            }

            foreach (self::STRING_COLUMNS as $column) {
                if (!Schema::hasColumn('venues', $column)) {
                    $table->string($column)->nullable();
                }
            }

            foreach (self::PRICE_COLUMNS as $column) {
                if (!Schema::hasColumn('venues', $column)) {
                    $table->decimal($column, 10, 2)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            array_merge(self::TEXT_COLUMNS, self::STRING_COLUMNS, self::PRICE_COLUMNS),
            fn (string $column) => Schema::hasColumn('venues', $column)
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('venues', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
