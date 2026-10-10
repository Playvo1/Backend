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
                'name_ar' => 'ÙƒØ±Ø© Ø§Ù„Ù‚Ø¯Ù…',
                'name_en' => 'Football',
            ],
            [
                'name_ar' => 'ÙƒØ±Ø© Ø§Ù„Ù‚Ø¯Ù…',
                'name_en' => 'Football',
                'icon_url' => null,
                'updated_at' => now(),
            ]
        );
    }
}
