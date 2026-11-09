<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Creates accounts on an admin's behalf: active, email verified, role assigned, and an
 * audit log entry attributing the action to the admin. Call it inside a DB transaction;
 * sending any credentials email is left to the caller, after the transaction commits.
 */
class AccountProvisioner
{
    public function create(
        string $name,
        string $email,
        ?string $phone,
        string $plainPassword,
        string $role,
        string $auditAction,
        int $adminUserId,
    ): User {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($plainPassword),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->assignRole($role);

        AuditLog::create([
            'admin_user_id' => $adminUserId,
            'action' => $auditAction,
            'target_type' => 'USER',
            'target_id' => $user->id,
        ]);

        return $user;
    }
}
