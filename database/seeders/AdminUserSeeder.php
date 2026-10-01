<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@playvo.app'],
            [
                'name' => 'Playvo Admin',
                'password' => Hash::make('Admin@123456'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $admin->syncRoles(['admin']);
    }
}
