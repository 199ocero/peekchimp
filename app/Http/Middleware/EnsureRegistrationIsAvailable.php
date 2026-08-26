<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationIsAvailable
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('register', 'register.store')) {
            return $next($request);
        }

        if (User::query()->where('is_admin', true)->doesntExist()) {
            return $next($request);
        }

        if ($request->routeIs('register')) {
            return to_route('login')->with('status', 'Registration is available by invitation only.');
        }

        abort(Response::HTTP_FORBIDDEN, 'Registration is available by invitation only.');
    }
}
