<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $countryId = DB::table('countries')->updateOrInsert(
            [
                'name_ar' => 'فلسطين',
                'name_en' => 'Palestine',
            ],
            [
                'name_ar' => 'فلسطين',
                'name_en' => 'Palestine',
                'updated_at' => now(),
            ]
        );

        $countryId = DB::table('countries')
            ->where('name_en', 'Palestine')
            ->value('id');


        $cities = [
            [
                'country_id' => $countryId,
                'name_ar' => 'غزة',
                'name_en' => 'Gaza',
            ],
            [
                'country_id' => $countryId,
                'name_ar' => 'خانيونس',
                'name_en' => 'Khan Yunis',
            ],
            [
                'country_id' => $countryId,
                'name_ar' => 'دير البلح',
                'name_en' => 'Deir al-Balah',
            ],
            [
                'country_id' => $countryId,
                'name_ar' => 'النصيرات',
                'name_en' => 'Nuseirat',
            ],
        ];

        foreach ($cities as $city) {
            DB::table('cities')->updateOrInsert(
                [
                    'country_id' => $city['country_id'],
                    'name_en' => $city['name_en'],
                ],
                [
                    'name_ar' => $city['name_ar'],
                    'name_en' => $city['name_en'],
                    'country_id' => $city['country_id'],
                    'updated_at' => now(),
                ]
            );
        }
    }
}
