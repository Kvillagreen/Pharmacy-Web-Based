<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class PreventConcurrentSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethodCacheable()) {
            $lockKey = $this->buildLockKey($request);
            $lock = Cache::lock($lockKey, 15);

            if (!$lock->get()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A similar request is already being processed. Please wait a moment and try again.',
                ], 429);
            }

            try {
                return $next($request);
            } finally {
                optional($lock)->release();
            }
        }

        return $next($request);
    }

    private function buildLockKey(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier() ?? 'guest';
        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key', ''));
        $payloadFingerprint = $idempotencyKey !== ''
            ? $idempotencyKey
            : hash('sha256', json_encode($request->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return sprintf(
            'request_lock:%s:%s:%s',
            $userId,
            $request->route()?->uri() ?? $request->path(),
            $payloadFingerprint
        );
    }
}
