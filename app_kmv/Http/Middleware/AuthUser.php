<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
    protected function redirectTo($request)
    {
        // Do not redirect for API requests
        if ($request->expectsJson()) {
            return null;
        }

        // Only redirect web requests
        return route('login');
    }
}
