<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\SuperAdmin;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SuperAdminAuthController extends Controller
{
    private function response($success, $message, $data = null, $extra = [])
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $extra));
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'super-admin|' . Str::lower($validated['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->response(false, 'Too many login attempts. Try again later.');
        }

        $admin = SuperAdmin::query()
            ->where('email', $validated['email'])
            ->first();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            RateLimiter::hit($key, 60);
            return $this->response(false, 'Invalid credentials');
        }

        RateLimiter::clear($key);

        $admin->tokens()->delete();

        $token = $admin->createToken(
            'super_admin_auth_token',
            ['super_admin'],
            Carbon::now()->addHours(8)
        );

        $admin->update([
            'login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_seen_ip' => $request->ip(),
        ]);

        return $this->response(true, 'Login successful', [
            'super_admin_id' => $admin->super_admin_id,
            'first_name' => $admin->first_name,
            'last_name' => $admin->last_name,
            'email' => $admin->email,
            'role' => 'super_admin',
            'address' => $admin->address,
            'login_at' => $admin->login_at,
            'last_login_ip' => $admin->last_login_ip,
            'registered_ip' => $admin->registered_ip,
            'created_at' => $admin->created_at,
        ], [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at ?? Carbon::now()->addHours(8),
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return $this->response(true, 'Logged out');
    }

    public function authUser(Request $request)
    {
        $admin = $request->user();

        if (!$admin instanceof SuperAdmin) {
            return $this->response(false, 'Super admin not logged in', null, [
                'authenticated' => false,
            ]);
        }

        $admin->update([
            'last_seen_ip' => $request->ip(),
        ]);

        return $this->response(true, 'Authenticated', [
            'super_admin_id' => $admin->super_admin_id,
            'first_name' => $admin->first_name,
            'last_name' => $admin->last_name,
            'email' => $admin->email,
            'role' => 'super_admin',
            'address' => $admin->address,
            'login_at' => $admin->login_at,
            'last_login_ip' => $admin->last_login_ip,
            'registered_ip' => $admin->registered_ip,
            'created_at' => $admin->created_at,
        ], [
            'authenticated' => true,
        ]);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'different:current_password'],
            'new_password_confirmation' => ['required', 'same:new_password'],
        ]);

        $admin = $request->user();

        if (!$admin instanceof SuperAdmin) {
            return $this->response(false, 'Super admin not found', null, [
                'authenticated' => false,
            ]);
        }

        if (!Hash::check($validated['current_password'], $admin->password)) {
            return $this->response(false, 'Current password is incorrect');
        }

        $admin->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return $this->response(true, 'Password changed successfully');
    }
}
