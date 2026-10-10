<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SportSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('sports')->updateOrInsert(
            [
                'name_ar' => 'كرة القدم',
                'name_en' => 'Football',
            ],
            [
                'name_ar' => 'كرة القدم',
                'name_en' => 'Football',
                'icon_url' => null,
                'updated_at' => now(),
            ]
        );
    }
}
