<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\RegisterRequest;
use App\Http\Requests\v1\LoginRequest;
use App\Models\v1\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\UserResource;

class AuthController extends Controller
{
    /**
     * Standard API Response
     */
    private function response($success, $message, $data = null, $extra = [])
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ], $extra));
    }

    /**
     * Login
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();
        $key = Str::lower($validated['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->response(false, 'Too many login attempts. Try again later.');
        }

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            return $this->response(false, 'Invalid credentials');
        }

        if ($user->status !== "approved") {
            return $this->response(false, 'Your account is not yet approved');
        }

        RateLimiter::clear($key);

        $user->tokens()->delete();

        $token = $user->createToken(
            'auth_token',
            ['user'],
            Carbon::now()->addHours(8)
        );

        return $this->response(true, 'Login successful', $user, [
            'token' => $token->plainTextToken,
            'data'=> $user,
            'expires_at' => $token->accessToken->expires_at ?? Carbon::now()->addHours(8),
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->response(true, 'Logged out');
    }

    /**
     * Register
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        try {
            User::create([
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'branch_id' => $validated['branchId'],
                'role' => $validated['role'],
                'address' => $validated['address'],
                'status' => 'pending',
            ]);

            return $this->response(true, 'Account created successfully');

        } catch (\Exception $e) {
            return $this->response(false, 'Failed to create account',$e);
        }
    }

    /**
     * Check Auth
     */
   public function AuthUser() {
    $user = auth()->user();

    if (!$user) {
        return $this->response(false, 'User not logged in', '', [
            'authenticated' => false,
        ]);
    }

    if ($user->status !== 'approved') {
        return $this->response(false, 'User not approved', '', [
            'authenticated' => false,
        ]);
    }

    return $this->response(true, 'Authenticated', '', [
        'authenticated' => true,
    ]);
}
}
