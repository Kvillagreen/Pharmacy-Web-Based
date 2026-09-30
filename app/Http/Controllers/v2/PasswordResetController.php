<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCodeMail;
use App\Models\v1\PasswordResetCode;
use App\Models\v1\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function request(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'If the email exists, a password reset code has been sent.',
            ]);
        }

        $existing = PasswordResetCode::query()
            ->where('email', $user->email)
            ->whereNull('used_at')
            ->latest('password_reset_code_id')
            ->first();

        if ($existing && $existing->resend_available_at && now()->lt($existing->resend_available_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting another code.',
                'resend_available_in' => now()->diffInSeconds($existing->resend_available_at),
            ], 429);
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);
        $resendAvailableAt = now()->addMinute();

        PasswordResetCode::query()
            ->where('email', $user->email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        PasswordResetCode::create([
            'user_id' => $user->user_id,
            'email' => $user->email,
            'code' => $code,
            'expires_at' => $expiresAt,
            'resend_available_at' => $resendAvailableAt,
        ]);

        try {
            $this->sendCodeMail($user, $code, $expiresAt);
        } catch (\Throwable $e) {
            $resetRow = PasswordResetCode::query()
                ->where('email', $user->email)
                ->where('code', $code)
                ->latest('password_reset_code_id')
                ->first();

            $resetRow?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Unable to send the reset email right now. Please verify your mail settings and try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset code sent successfully.',
            'resend_available_in' => 60,
        ]);
    }

    public function resend(Request $request)
    {
        return $this->request($request);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $resetCode = PasswordResetCode::query()
            ->where('email', $validated['email'])
            ->where('code', $validated['code'])
            ->whereNull('used_at')
            ->latest('password_reset_code_id')
            ->first();

        if (!$resetCode) {
            throw ValidationException::withMessages([
                'code' => 'Invalid reset code.',
            ]);
        }

        if (now()->gt($resetCode->expires_at)) {
            $resetCode->update(['used_at' => now()]);

            throw ValidationException::withMessages([
                'code' => 'This reset code has already expired.',
            ]);
        }

        $user = User::query()->find($resetCode->user_id);
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => 'Account not found.',
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        $user->tokens()->delete();
        $resetCode->update(['used_at' => now()]);

        PasswordResetCode::query()
            ->where('email', $validated['email'])
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful. You can now sign in with your new password.',
        ]);
    }

    private function sendCodeMail(User $user, string $code, Carbon $expiresAt): void
    {
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        Mail::to($user->email)->send(new PasswordResetCodeMail(
            recipientName: $name !== '' ? $name : 'User',
            code: $code,
            expiresAtText: $expiresAt->format('F j, Y g:i A'),
            appName: config('app.name', 'Pharmacy System')
        ));
    }
}
