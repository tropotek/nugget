<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an API token whose user has been deactivated, as if the token did
 * not exist: a deactivated user is told nothing an unknown one would not be.
 */
class EnsureTokenUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->active) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
