<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    private const OTP_EXPIRES_MINUTES = 5;

    private const OTP_LENGTH = 6;

    private const MAX_OTP_ATTEMPTS = 5;

    private const RL_REQUESTS_PER_IP_PER_MINUTE = 5;

    private const RL_REQUESTS_PER_EMAIL_PER_5MIN = 3;

    private const RL_VERIFY_PER_REQUEST_PER_MINUTE = 10;

    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim((string) $validated['email']));
        $ip = $request->ip() ?? '0.0.0.0';

        $ipKey = 'pwdreset:request:ip:'.$ip;
        if (RateLimiter::tooManyAttempts($ipKey, self::RL_REQUESTS_PER_IP_PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($ipKey);
            throw ValidationException::withMessages([
                'email' => ["Too many requests. Please try again in {$seconds} seconds."],
            ]);
        }

        $emailKey = 'pwdreset:request:email:'.sha1($email);
        if (RateLimiter::tooManyAttempts($emailKey, self::RL_REQUESTS_PER_EMAIL_PER_5MIN)) {
            $seconds = RateLimiter::availableIn($emailKey);
            throw ValidationException::withMessages([
                'email' => ["Too many requests for this email. Please try again in {$seconds} seconds."],
            ]);
        }

        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($emailKey, self::OTP_EXPIRES_MINUTES * 60);

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $email)
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Email not found.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Account is deactivated.'],
            ]);
        }

        $otp = str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
        $requestId = (string) Str::uuid();

        $record = PasswordResetOtp::create([
            'request_id' => $requestId,
            'user_id' => $user->id,
            'email' => $email,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(self::OTP_EXPIRES_MINUTES),
            'attempts' => 0,
        ]);

        try {
            Mail::to($user->email)->send(new PasswordResetOtpMail($otp, self::OTP_EXPIRES_MINUTES));
        } catch (\Throwable $e) {
            // If mail fails, invalidate the request to avoid "ghost" OTPs.
            $record->delete();
            throw ValidationException::withMessages([
                'email' => ['Could not send OTP email. Please try again later.'],
            ]);
        }

        return response()->json([
            'message' => 'OTP sent to your email address.',
            'request_id' => $requestId,
            'expires_in_seconds' => self::OTP_EXPIRES_MINUTES * 60,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'otp.regex' => 'OTP must be a 6-digit code.',
        ]);

        $requestId = (string) $validated['request_id'];
        $otp = (string) $validated['otp'];
        $ip = $request->ip() ?? '0.0.0.0';

        $verifyKey = 'pwdreset:verify:'.$requestId.':'.$ip;
        if (RateLimiter::tooManyAttempts($verifyKey, self::RL_VERIFY_PER_REQUEST_PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($verifyKey);
            throw ValidationException::withMessages([
                'otp' => ["Too many attempts. Please try again in {$seconds} seconds."],
            ]);
        }
        RateLimiter::hit($verifyKey, 60);

        /** @var PasswordResetOtp|null $record */
        $record = PasswordResetOtp::query()
            ->where('request_id', $requestId)
            ->first();

        if (! $record || $record->isUsed() || $record->isExpired()) {
            throw ValidationException::withMessages([
                'otp' => ['OTP is invalid or expired. Please request a new one.'],
            ]);
        }

        if ($record->attempts >= self::MAX_OTP_ATTEMPTS) {
            $record->used_at = now();
            $record->save();
            throw ValidationException::withMessages([
                'otp' => ['Too many failed attempts. Please request a new OTP.'],
            ]);
        }

        if (! Hash::check($otp, (string) $record->otp_hash)) {
            $record->attempts = min(255, (int) $record->attempts + 1);
            $record->save();
            throw ValidationException::withMessages([
                'otp' => ['Incorrect OTP.'],
            ]);
        }

        // Successful verification: mint a short-lived reset token tied to this request.
        $resetToken = bin2hex(random_bytes(32));
        $record->reset_token_hash = Hash::make($resetToken);
        $record->verified_at = now();
        $record->attempts = min(255, (int) $record->attempts + 1); // count success as an attempt to keep one-time semantics strict
        $record->save();

        return response()->json([
            'message' => 'OTP verified.',
            'request_id' => $record->request_id,
            'reset_token' => $resetToken,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'reset_token' => ['required', 'string', 'min:10'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $requestId = (string) $validated['request_id'];
        $resetToken = (string) $validated['reset_token'];

        /** @var PasswordResetOtp|null $record */
        $record = PasswordResetOtp::query()
            ->where('request_id', $requestId)
            ->first();

        if (! $record || $record->isUsed() || $record->isExpired() || $record->verified_at === null) {
            throw ValidationException::withMessages([
                'request_id' => ['Reset request is invalid or expired. Please start again.'],
            ]);
        }

        if (! $record->reset_token_hash || ! Hash::check($resetToken, (string) $record->reset_token_hash)) {
            throw ValidationException::withMessages([
                'reset_token' => ['Reset token is invalid. Please start again.'],
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('id', $record->user_id)->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'request_id' => ['User not found.'],
            ]);
        }

        $user->password = Hash::make((string) $validated['password']);
        $user->save();

        // Revoke existing sessions/tokens.
        $user->tokens()->delete();

        $record->used_at = now();
        $record->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
