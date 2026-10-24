<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
 public function up(): void
{
    $football = \Illuminate\Support\Facades\DB::table('sports')
        ->where(function ($query) {
            $query->where('name_en', 'Football')
                ->orWhere('name_ar', 'كرة القدم');
        })
        ->first();

    if (!$football) {
        return;
    }

    $venues = \Illuminate\Support\Facades\DB::table('venues')
        ->select('id')
        ->get();

    foreach ($venues as $venue) {
        \Illuminate\Support\Facades\DB::table('venue_sports')
            ->insertOrIgnore([
                'venue_id' => $venue->id,
                'sport_id' => $football->id,
            ]);
    }
}

public function down(): void
{}

    /**
     * Reverse the migrations.
     */

};
