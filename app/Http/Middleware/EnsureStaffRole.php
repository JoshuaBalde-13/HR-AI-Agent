<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffRole
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return redirect()->guest(route('staff.login'));
        }

        if (!$user->hasRole(...$roles)) {
            abort(403, 'You are not allowed to access this page.');
        }

        return $next($request);
    }
}