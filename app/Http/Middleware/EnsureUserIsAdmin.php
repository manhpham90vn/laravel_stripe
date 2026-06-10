<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Chặn buyer/guest truy cập /admin/* → trả 403 (spec §11). Gắn dưới alias 'admin'. */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Không đăng nhập (?->) hoặc không phải admin → 403.
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
