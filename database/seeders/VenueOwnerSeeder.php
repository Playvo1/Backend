<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class VenueOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'venueowner@playvo.test'],
            [
                'name' => 'Playvo Venue Owner',
                'phone' => '0599999999',
                'password' => Hash::make('Password123!'),
                'status' => 'active',
            ]
        );

        $role = Role::firstOrCreate([
            'name' => 'venue_owner',
            'guard_name' => 'web',
        ]);

        $user->assignRole($role);

        Venue::where('id', 1)->update([
            'owner_id' => $user->id,
        ]);
    }
}
