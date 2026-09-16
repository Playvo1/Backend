<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\User;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\OtpMail;
use App\Models\VerificationCode;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\VerifyResetOtpRequest;
use App\Http\Requests\googleAuthRequest;
use Google\Client as GoogleClient;
use Illuminate\Support\Str;
class AuthController extends Controller
{
   public function registerPlayer(RegisterRequest $request)
{
    return $this->register($request, 'player');
}

public function register(RegisterRequest $request, string $role)
{
    // التأكد أن الإيميل غير مسجل مسبقًا
    $existingUser = User::where('email', $request->email)->first();

    if ($existingUser) {
        return ApiResponse::send(
            false,
            422,
            'Email is already registered'
        );
    }

    // إنشاء المستخدم
    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'phone' => $request->phone,
        'password' => Hash::make($request->password),
        'status' => 'active',
        'email_verified_at' => null,
    ]);

    // إعطاء المستخدم Role
    $user->assignRole($role);

    return ApiResponse::send(
        true,
        200,
        'Registration successful. Please verify your email.'
    );

}

public function googleAuth(googleAuthRequest $request)
{
    $client = new GoogleClient([
        'client_id' => config('services.google.client_id'),
    ]);

    $payload = $client->verifyIdToken($request->id_token);

    if (!$payload) {
        return ApiResponse::send(
            false,
            401,
            'Invalid Google token.'
        );
    }

    $googleId = $payload['sub'];
    $email = $payload['email'];
    $name = $payload['name'] ?? 'Google User';

    // البحث عن حساب Google
    $user = User::where('google_id', $googleId)->first();

    if (!$user) {

        // البحث عن حساب بنفس الإيميل
        $user = User::where('email', $email)->first();

        if ($user) {

            // ربط حساب Google بالحساب الموجود
            $user->update([
                'google_id' => $googleId,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

        } else {

            // إنشاء مستخدم جديد
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => null,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'status' => 'active',
            ]);

            $user->assignRole('player');
        }
    }

    // إنشاء Sanctum token
    $token = $user->createToken('auth_token')->plainTextToken;

    return ApiResponse::send(
        true,
        200,
        'Google authentication successful.',
        [
            'token' => $token,
            'user' => $user,
        ]
    );
}
public function sendOtp(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:users,email',
    ]);

    $user = User::where('email', $request->email)->first();

    if ($user->email_verified_at) {
        return ApiResponse::send(
            false,
            422,
            'Email is already verified.'
        );
    }

    $otp = random_int(100000, 999999);

    VerificationCode::create([
        'user_id' => $user->id,
        'code' => $otp,
        'type' => 'registration',
        'expires_at' => now()->addMinutes(10),
        'used_at' => null,
    ]);

    Mail::to($user->email)->send(
        new OtpMail($otp)
    );

    return ApiResponse::send(
        true,
        200,
        'OTP sent successfully to your email.'
    );
}public function verifyOtp(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:users,email',
        'otp_code' => 'required|digits:6',
    ]);

    $user = User::where('email', $request->email)->first();

    $verificationCode = VerificationCode::where('user_id', $user->id)
        ->where('code', $request->otp_code)
        ->where('type', 'registration')
        ->where('expires_at', '>', now())
        ->whereNull('used_at')
        ->first();

    if (!$verificationCode) {
        return ApiResponse::send(
            false,
            422,
            'Invalid or expired OTP.'
        );
    }

    $user->email_verified_at = now();
    $user->save();

    $verificationCode->used_at = now();
    $verificationCode->save();

    return ApiResponse::send(
        true,
        200,
        'Email verified successfully.'
    );
}

public function login(LoginRequest $request)
{
    $user = User::where('email', $request->email)->first();

    // Check if account is currently locked
    if ($user && $user->locked_until && now()->lessThan($user->locked_until)) {
        return ApiResponse::send(
            false,
            423,
            'Your account is temporarily locked. Please try again later.'
        );
    }

    // Reset lockout after 15 minutes
    if ($user && $user->locked_until && now()->greaterThanOrEqualTo($user->locked_until)) {
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    // Check credentials
    if (!$user || !Hash::check($request->password, $user->password)) {

        if ($user) {
            $user->increment('failed_login_attempts');

            // Lock account after 5 failed attempts
            if ($user->failed_login_attempts >= 5) {
                $user->update([
                    'locked_until' => now()->addMinutes(15),
                ]);

                return ApiResponse::send(
                    false,
                    423,
                    'Your account has been temporarily locked for 15 minutes.'
                );
            }
        }

        return ApiResponse::send(
            false,
            401,
            'Invalid credentials.'
        );
    }

    // Check account status
    if ($user->status !== 'active') {
        return ApiResponse::send(
            false,
            403,
            'Your account is not active.'
        );
    }

    // Check email verification
    if (!$user->email_verified_at) {
        return ApiResponse::send(
            false,
            403,
            'Please verify your email before logging in.'
        );
    }

    // Reset failed login attempts after successful login
    $user->update([
        'failed_login_attempts' => 0,
        'locked_until' => null,
    ]);

    // Create authentication token
    $token = $user->createToken('auth_token')->plainTextToken;

    return ApiResponse::send(
        true,
        200,
        'Login successful.',
        [
            'access_token' => $token,
            'token_type' => 'Bearer'
        ]
    );

    }
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::send(
            true,
            200,
            'Logout successful.'
        );
    }
   public function forgotPassword(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:users,email',
    ]);

    $user = User::where('email', $request->email)->first();

    $otp = random_int(100000, 999999);

    VerificationCode::create([
        'user_id' => $user->id,
        'code' => $otp,
        'type' => 'password_reset',
        'expires_at' => now()->addMinutes(10),
        'used_at' => null,
    ]);

    Mail::to($user->email)->send(
        new OtpMail($otp)
    );

    return ApiResponse::send(
        true,
        200,
        'OTP sent successfully to your email for password reset.'
    );
}

public function verifyResetOtp(VerifyResetOtpRequest $request)
{
    $user = User::where('email', $request->email)->first();

    $verificationCode = VerificationCode::where('user_id', $user->id)
        ->where('code', $request->otp_code)
        ->where('type', 'password_reset')
        ->where('expires_at', '>', now())
        ->whereNull('used_at')
        ->first();

    if (!$verificationCode) {
        return ApiResponse::send(
            false,
            422,
            'Invalid or expired OTP.'
        );
    }

    $resetToken = Str::random(64);

  $verificationCode->reset_token = $resetToken;
   $verificationCode->save();

    return ApiResponse::send(
    true,
    200,
    'OTP verified successfully. You can now reset your password.',
    [
        'reset_token' => $resetToken,
    ]
);
}
public function resetPassword(Request $request)
{
    $request->validate([
        'reset_token' => 'required|string',
        'password' => 'required|string|min:8|confirmed',
    ]);

    $verificationCode = VerificationCode::where('reset_token', $request->reset_token)
        ->where('type', 'password_reset')
        ->where('expires_at', '>', now())
        ->whereNull('used_at')
        ->first();

    if (!$verificationCode) {
        return ApiResponse::send(
            false,
            422,
            'Invalid or expired reset token.'
        );
    }

    $user = User::find($verificationCode->user_id);

    if (!$user) {
        return ApiResponse::send(
            false,
            404,
            'User not found.'
        );
    }

    $user->password = Hash::make($request->password);
    $user->save();

    $verificationCode->used_at = now();
    $verificationCode->save();

    return ApiResponse::send(
        true,
        200,
        'Password reset successful.'
    );
}
}
