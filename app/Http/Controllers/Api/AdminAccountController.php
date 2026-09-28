<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateAdminRequest;
use App\Http\Requests\Api\CreateVenueOwnerRequest;
use App\Mail\TemporaryPasswordMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Lets an authenticated admin provision venue-owner and admin accounts directly
 * (US-1.4 / US-1.5). Neither role can self-register; routes are restricted to
 * admins via the auth:sanctum + role:admin middleware.
 */
class AdminAccountController extends Controller
{
    public function createVenueOwner(CreateVenueOwnerRequest $request): JsonResponse
    {
        return $this->provisionAccount(
            $request,
            role: 'venue_owner',
            auditAction: 'venue_owner_created',
            successMessage: 'Venue owner created, credentials sent by email',
        );
    }

    public function createAdmin(CreateAdminRequest $request): JsonResponse
    {
        return $this->provisionAccount(
            $request,
            role: 'admin',
            auditAction: 'admin_created',
            successMessage: 'Admin created, credentials sent by email',
        );
    }

    /**
     * Creates the user with a random temporary password, assigns the role, and
     * writes the audit log entry attributing the action to the acting admin, all
     * inside one transaction so a failure never leaves an orphaned account behind.
     * The credentials email is sent once that transaction has committed.
     */
    private function provisionAccount(FormRequest $request, string $role, string $auditAction, string $successMessage): JsonResponse
    {
        $temporaryPassword = Str::password(12);
        $adminUserId = $request->user()->id;

        $user = DB::transaction(function () use ($request, $role, $auditAction, $temporaryPassword, $adminUserId) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($temporaryPassword),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $user->assignRole($role);

            $this->logAdminAction($adminUserId, $auditAction, $user->id);

            return $user;
        });

        Mail::to($user->email)->send(new TemporaryPasswordMail($user->name, $temporaryPassword));

        return ApiResponse::send(true, 201, $successMessage, [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ]);
    }

    private function logAdminAction(int $adminUserId, string $action, int $targetUserId): void
    {
        AuditLog::create([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'target_type' => 'USER',
            'target_id' => $targetUserId,
        ]);
    }
}
