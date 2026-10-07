<?php

namespace App\Http\Middleware;

use App\Models\Applicant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnApplicant
{
    public function handle(Request $request, Closure $next): Response
    {
        $me = Auth::guard('applicant')->user();

        if (!$me) {
            return redirect()->route('login');
        }

        $routeApplicant = $request->route('applicant');

        $routeId = $routeApplicant instanceof Applicant
            ? (int) $routeApplicant->getKey()
            : (int) $routeApplicant;

        // Wrong applicant in the URL: send them to their own dashboard.
        if ($routeId !== (int) $me->getKey()) {
            return redirect()->route('applicant.dashboard', $me);
        }

        return $next($request);
    }
}