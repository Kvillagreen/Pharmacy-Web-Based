<?php

namespace App\Http\Middleware;

use App\Models\v1\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof SuperAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Super admin access is required.',
            ], 403);
        }

        return $next($request);
    }
}
