<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureStickerAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $key = config('stickers.admin_key');

        abort_if(
            !$key || !hash_equals($key, (string) $request->header('X-Sticker-Admin-Key')),
            403,
            'Forbidden'
        );

        return $next($request);
    }
}