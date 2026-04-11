<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\RegisterRequest;
use App\Http\Requests\v1\LoginRequest;
use App\Models\v1\Permission;
use App\Models\v1\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
            'data' => $data,
        ], $extra));
    }

    /**
     * Login
     */
    public function login(LoginRequest $request)
    {
        try{
            $validated = $request->validated();
        $key = Str::lower($validated['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->response(false, 'Too many login attempts. Try again later.');
        }

        $user = User::with('permissions')
            ->where('email', $validated['email'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            return $this->response(false, 'Invalid credentials');
        }

        if ($user->status !== 'approved') {
            return $this->response(false, 'Your account is not yet approved');
        }

        RateLimiter::clear($key);

        // Remove old tokens
        $user->tokens()->delete();

        // Get permission names
        $permissionNames = $user->permissions
            ->pluck('permission_name')
            ->values()
            ->toArray();

        // Create token using permission names as Sanctum abilities
        $token = $user->createToken(
            'auth_token',
            $permissionNames,
            Carbon::now()->addHours(8)
        );

        $user->update([
            'login_at' => now(),
        ]);

        $companyData = User::join('branches', 'users.branch_id', '=', 'branches.branch_id')
            ->join('companies', 'branches.company_id', '=', 'companies.company_id')
            ->where('users.user_id', $user->user_id)
            ->select([
                'companies.company_id',
                'companies.company_name',
                'companies.company_email',
                'companies.tin_number',
            ])
            ->first();

        $responseUser = [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'role' => $user->role,
            'address' => $user->address,
            'status' => $user->status,
            'company_id' => $companyData?->company_id,
            'company_name' => $companyData?->company_name,
            'company_email' => $companyData?->company_email,
            'tin_number' => $companyData?->tin_number,
            'permissions' => $permissionNames,
            'created_at' => $user->created_at,
            'login_at' => $user->login_at,

        ];

        return $this->response(true, 'Login successful', $responseUser, [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at ?? Carbon::now()->addHours(8),
        ]);
        }
        catch(\Exception $e){
            \Log::error('Login error', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);
            return $this->response(false, 'An error occurred during login',
            [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]
            );
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }
        return $this->response(true, 'Logged out');
    }

    /**
     * Register
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        try {
            $user = User::create([
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'branch_id' => $validated['branchId'],
                'role' => $validated['role'],
                'address' => $validated['address'],
                'status' => 'pending',
            ]);

            $user->permissions()->sync(1);
            $user->load('permissions');

            return $this->response(true, 'Account created successfully', [
                'user_id' => $user->user_id,
            ]);
        } catch (\Exception $e) {
            return $this->response(false, 'Failed to create account', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check Auth
     */
    public function AuthUser(Request $request)
    {
        $user = User::with('permissions')->find(auth()->id());

        if (!$user) {
            return $this->response(false, 'User not logged in', null, [
                'authenticated' => false,
            ]);
        }

        if ($user->status !== 'approved') {
            return $this->response(false, 'User not approved', null, [
                'authenticated' => false,
            ]);
        }
        $companyData = User::join('branches', 'users.branch_id', '=', 'branches.branch_id')
                    ->join('companies', 'branches.company_id', '=', 'companies.company_id')
                    ->where('users.user_id', $user->user_id)
                    ->select([
                        'companies.company_id',
                        'companies.company_name',
                        'companies.company_email',
                        'companies.tin_number',
                    ])
                    ->first();

        $permissionNames = $user->permissions
            ->pluck('permission_name')
            ->values()
            ->toArray();

        return $this->response(true, 'Authenticated', [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'role' => $user->role,
            'address' => $user->address,
            'status' => $user->status,
            'company_id' => $companyData?->company_id,
            'company_name' => $companyData?->company_name,
            'company_email' => $companyData?->company_email,
            'tin_number' => $companyData?->tin_number,
            'permissions' => $permissionNames,
            'created_at' => $user->created_at,
            'login_at' => $user->login_at,

        ], [
            'authenticated' => true,
        ]);
    }
}
