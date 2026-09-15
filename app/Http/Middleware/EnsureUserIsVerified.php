<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->is_verified) {
            return response()->json([
                'message' => 'Your account must be verified before you can use SwiftChat.',
                'code' => 'ACCOUNT_NOT_VERIFIED',
            ], 403);
        }

        return $next($request);
    }
}