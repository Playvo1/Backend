<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialAdminSeeder extends Seeder
{
    /**
     * Creates the first admin on a fresh deployment from ADMIN_EMAIL / ADMIN_PASSWORD,
     * since admins can't self-register. Does nothing if the vars are unset or the
     * account already exists, so it is safe to run on every start.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password || User::where('email', $email)->exists()) {
            return;
        }

        User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
            'email_verified_at' => now(),
        ])->assignRole('admin');
    }
}
